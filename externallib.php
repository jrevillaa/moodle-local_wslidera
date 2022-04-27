<?php

// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External Web Service Template
 *
 * @package    localwstemplate
 * @copyright  2011 Moodle Pty Ltd (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->libdir . "/externallib.php");
require_once("$CFG->libdir/completionlib.php");
require_once("$CFG->dirroot/mod/assign/locallib.php");

class local_wslidera_external extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     * @since Moodle 2.2
     */
    public static function enrol_users_parameters() {
        return new external_function_parameters(
                array(
                    'enrolments' => new external_multiple_structure(
                            new external_single_structure(
                                    array(
                                        'roleid' => new external_value(PARAM_INT, 'Role to assign to the user'),
                                        'userid' => new external_value(PARAM_INT, 'The user that is going to be enrolled'),
                                        'courseid' => new external_value(PARAM_INT, 'The course to enrol the user role in'),
                                        'timestart' => new external_value(PARAM_INT, 'Timestamp when the enrolment start', VALUE_OPTIONAL),
                                        'timeend' => new external_value(PARAM_INT, 'Timestamp when the enrolment end', VALUE_OPTIONAL),
                                        'suspend' => new external_value(PARAM_INT, 'set to 1 to suspend the enrolment', VALUE_OPTIONAL),
                                        'trandate' => new external_value(PARAM_INT, 'set to 1 to suspend the enrolment', VALUE_OPTIONAL)
                                    )
                            )
                    )
                )
        );
    }

    /**
     * Enrolment of users.
     *
     * Function throw an exception at the first error encountered.
     * @param array $enrolments  An array of user enrolment
     * @since Moodle 2.2
     */
    public static function enrol_users($enrolments) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $params = self::validate_parameters(self::enrol_users_parameters(),
                array('enrolments' => $enrolments));

        $transaction = $DB->start_delegated_transaction(); // Rollback all enrolment if an error occurs
                                                           // (except if the DB doesn't support it).

        // Retrieve the manual enrolment plugin.
        $enrol = enrol_get_plugin('manual');
        if (empty($enrol)) {
            throw new moodle_exception('manualpluginnotinstalled', 'enrol_manual');
        }

        foreach ($params['enrolments'] as $enrolment) {
            // Ensure the current user is allowed to run this function in the enrolment context.
            $context = context_course::instance($enrolment['courseid'], IGNORE_MISSING);
            self::validate_context($context);

            // Check that the user has the permission to manual enrol.
            require_capability('enrol/manual:enrol', $context);

            // Throw an exception if user is not able to assign the role.
            $roles = get_assignable_roles($context);
            if (!array_key_exists($enrolment['roleid'], $roles)) {
                $errorparams = new stdClass();
                $errorparams->roleid = $enrolment['roleid'];
                $errorparams->courseid = $enrolment['courseid'];
                $errorparams->userid = $enrolment['userid'];
                throw new moodle_exception('wsusercannotassign', 'enrol_manual', '', $errorparams);
            }

            // Check manual enrolment plugin instance is enabled/exist.
            $instance = null;
            $enrolinstances = enrol_get_instances($enrolment['courseid'], true);
            foreach ($enrolinstances as $courseenrolinstance) {
              if ($courseenrolinstance->enrol == "manual") {
                  $instance = $courseenrolinstance;
                  break;
              }
            }
            if (empty($instance)) {
              $errorparams = new stdClass();
              $errorparams->courseid = $enrolment['courseid'];
              throw new moodle_exception('wsnoinstance', 'enrol_manual', $errorparams);
            }

            // Check that the plugin accept enrolment (it should always the case, it's hard coded in the plugin).
            if (!$enrol->allow_enrol($instance)) {
                $errorparams = new stdClass();
                $errorparams->roleid = $enrolment['roleid'];
                $errorparams->courseid = $enrolment['courseid'];
                $errorparams->userid = $enrolment['userid'];
                throw new moodle_exception('wscannotenrol', 'enrol_manual', '', $errorparams);
            }

            // Finally proceed the enrolment.
            $enrolment['timestart'] = isset($enrolment['timestart']) ? $enrolment['timestart'] : 0;
            $enrolment['timeend'] = isset($enrolment['timeend']) ? $enrolment['timeend'] : 0;
            $enrolment['status'] = (isset($enrolment['suspend']) && !empty($enrolment['suspend'])) ?
                    ENROL_USER_SUSPENDED : ENROL_USER_ACTIVE;
            //modify
            $sql = "
            SELECT 
                ue.id,
                ue.timemodified
            FROM mdl_user u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {context} ct ON ct.id = ra.contextid
                AND ct.contextlevel = 50
                JOIN mdl_course c ON c.id = ct.instanceid
                AND e.courseid = c.id
                JOIN mdl_role r ON r.id = ra.roleid
            WHERE c.id = :courseid
                AND u.id = :userid
                AND r.id = :roleid
                AND e.status = 0
                AND u.suspended = 0
                AND u.deleted = 0
                AND ue.status = 0
            ";
            $sql_array = [
                'courseid' => $enrolment['courseid'],
                'userid' => $enrolment['userid'],
                'roleid' => $enrolment['roleid'],
            ];

            $us_enrol = $DB->get_record_sql($sql,$sql_array);

            if($us_enrol->timemodified > $enrolment['trandate']){
                continue;
            }
            $us_enrol->timemodified = $enrolment['trandate'];
            $DB->update_record('user_enrolments', $us_enrol);

            //modify

            $enrol->enrol_user($instance, $enrolment['userid'], $enrolment['roleid'],
                    $enrolment['timestart'], $enrolment['timeend'], $enrolment['status']);

        }

        $transaction->allow_commit();
    }

    /**
     * Returns description of method result value.
     *
     * @return null
     * @since Moodle 2.2
     */
    public static function enrol_users_returns() {
        return null;
    }


    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.2
     */
    public static function update_users_parameters() {
        $userfields = [
            'id' => new external_value(core_user::get_property_type('id'), 'ID of the user'),
            // General.
            'username' => new external_value(core_user::get_property_type('username'),
                'Username policy is defined in Moodle security config.', VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
            'auth' => new external_value(core_user::get_property_type('auth'), 'Auth plugins include manual, ldap, etc',
                VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
            'suspended' => new external_value(core_user::get_property_type('suspended'),
                'Suspend user account, either false to enable user login or true to disable it', VALUE_OPTIONAL),
            'password' => new external_value(core_user::get_property_type('password'),
                'Plain text password consisting of any characters', VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
            'firstname' => new external_value(core_user::get_property_type('firstname'), 'The first name(s) of the user',
                VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
            'lastname' => new external_value(core_user::get_property_type('lastname'), 'The family name of the user',
                VALUE_OPTIONAL),
            'email' => new external_value(core_user::get_property_type('email'), 'A valid and unique email address', VALUE_OPTIONAL,
                '', NULL_NOT_ALLOWED),
            'maildisplay' => new external_value(core_user::get_property_type('maildisplay'), 'Email display', VALUE_OPTIONAL),
            'city' => new external_value(core_user::get_property_type('city'), 'Home city of the user', VALUE_OPTIONAL),
            'country' => new external_value(core_user::get_property_type('country'),
                'Home country code of the user, such as AU or CZ', VALUE_OPTIONAL),
            'timezone' => new external_value(core_user::get_property_type('timezone'),
                'Timezone code such as Australia/Perth, or 99 for default', VALUE_OPTIONAL),
            'description' => new external_value(core_user::get_property_type('description'), 'User profile description, no HTML',
                VALUE_OPTIONAL),
            // User picture.
            'userpicture' => new external_value(PARAM_INT,
                'The itemid where the new user picture has been uploaded to, 0 to delete', VALUE_OPTIONAL),
            // Additional names.
            'firstnamephonetic' => new external_value(core_user::get_property_type('firstnamephonetic'),
                'The first name(s) phonetically of the user', VALUE_OPTIONAL),
            'lastnamephonetic' => new external_value(core_user::get_property_type('lastnamephonetic'),
                'The family name phonetically of the user', VALUE_OPTIONAL),
            'middlename' => new external_value(core_user::get_property_type('middlename'), 'The middle name of the user',
                VALUE_OPTIONAL),
            'alternatename' => new external_value(core_user::get_property_type('alternatename'), 'The alternate name of the user',
                VALUE_OPTIONAL),
            // Interests.
            'interests' => new external_value(PARAM_TEXT, 'User interests (separated by commas)', VALUE_OPTIONAL),
            // Optional.
            'url' => new external_value(PARAM_RAW, 'User web page', VALUE_OPTIONAL),
            'icq' => new external_value(PARAM_RAW, 'ICQ number', VALUE_OPTIONAL),
            'skype' => new external_value(PARAM_RAW, 'Skype ID', VALUE_OPTIONAL),
            'aim' => new external_value(PARAM_RAW, 'AIM ID', VALUE_OPTIONAL),
            'yahoo' => new external_value(PARAM_RAW, 'Yahoo ID', VALUE_OPTIONAL),
            'msn' => new external_value(PARAM_RAW, 'MSN ID', VALUE_OPTIONAL),
            'idnumber' => new external_value(core_user::get_property_type('idnumber'),
                'An arbitrary ID code number perhaps from the institution', VALUE_OPTIONAL),
            'institution' => new external_value(core_user::get_property_type('institution'), 'Institution', VALUE_OPTIONAL),
            'department' => new external_value(core_user::get_property_type('department'), 'Department', VALUE_OPTIONAL),
            'phone1' => new external_value(core_user::get_property_type('phone1'), 'Phone', VALUE_OPTIONAL),
            'phone2' => new external_value(core_user::get_property_type('phone2'), 'Mobile phone', VALUE_OPTIONAL),
            'address' => new external_value(core_user::get_property_type('address'), 'Postal address', VALUE_OPTIONAL),
            // Other user preferences stored in the user table.
            'lang' => new external_value(core_user::get_property_type('lang'), 'Language code such as "en", must exist on server',
                VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
            'calendartype' => new external_value(core_user::get_property_type('calendartype'),
                'Calendar type such as "gregorian", must exist on server', VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
            'theme' => new external_value(core_user::get_property_type('theme'),
                'Theme name such as "standard", must exist on server', VALUE_OPTIONAL),
            'mailformat' => new external_value(core_user::get_property_type('mailformat'),
                'Mail format code is 0 for plain text, 1 for HTML etc', VALUE_OPTIONAL),
            // Custom user profile fields.
            'customfields' => new external_multiple_structure(
                new external_single_structure(
                    [
                        'type'  => new external_value(PARAM_ALPHANUMEXT, 'The name of the custom field'),
                        'value' => new external_value(PARAM_RAW, 'The value of the custom field')
                    ]
                ), 'User custom fields (also known as user profil fields)', VALUE_OPTIONAL),
            // User preferences.
            'preferences' => new external_multiple_structure(
                new external_single_structure(
                    [
                        'type'  => new external_value(PARAM_RAW, 'The name of the preference'),
                        'value' => new external_value(PARAM_RAW, 'The value of the preference')
                    ]
                ), 'User preferences', VALUE_OPTIONAL),
            'timemodified' => new external_value(PARAM_INT,
                'Time modified', VALUE_OPTIONAL),
        ];
        return new external_function_parameters(
            [
                'users' => new external_multiple_structure(
                    new external_single_structure($userfields)
                )
            ]
        );
    }

    /**
     * Update users
     *
     * @param array $users
     * @return null
     * @since Moodle 2.2
     */
    public static function update_users($users) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot."/user/lib.php");
        require_once($CFG->dirroot."/user/profile/lib.php"); // Required for customfields related function.
        require_once($CFG->dirroot.'/user/editlib.php');

        // Ensure the current user is allowed to run this function.
        $context = context_system::instance();
        require_capability('moodle/user:update', $context);
        self::validate_context($context);

        $params = self::validate_parameters(self::update_users_parameters(), array('users' => $users));

        $filemanageroptions = array('maxbytes' => $CFG->maxbytes,
            'subdirs'        => 0,
            'maxfiles'       => 1,
            'accepted_types' => 'optimised_image');

        $transaction = $DB->start_delegated_transaction();

        foreach ($params['users'] as $user) {
            // First check the user exists.
            if (!$existinguser = core_user::get_user($user['id'])) {
                continue;
            }
            // Check if we are trying to update an admin.
            if ($existinguser->id != $USER->id and is_siteadmin($existinguser) and !is_siteadmin($USER)) {
                continue;
            }
            // Other checks (deleted, remote or guest users).
            if ($existinguser->deleted or is_mnet_remote_user($existinguser) or isguestuser($existinguser->id)) {
                continue;
            }
            // Check duplicated emails.
            if (isset($user['email']) && $user['email'] !== $existinguser->email) {
                if (!validate_email($user['email'])) {
                    continue;
                } else if (empty($CFG->allowaccountssameemail)) {
                    // Make a case-insensitive query for the given email address and make sure to exclude the user being updated.
                    $select = $DB->sql_equal('email', ':email', false) . ' AND mnethostid = :mnethostid AND id <> :userid';
                    $params = array(
                        'email' => $user['email'],
                        'mnethostid' => $CFG->mnet_localhost_id,
                        'userid' => $user['id']
                    );
                    // Skip if there are other user(s) that already have the same email.
                    if ($DB->record_exists_select('user', $select, $params)) {
                        continue;
                    }
                }
            }

            if($existinguser->timemodified > $user['timemodified']){
                continue;
            }

            user_update_user($user, true, false);

            $userobject = (object)$user;

            // Update user picture if it was specified for this user.
            if (empty($CFG->disableuserimages) && isset($user['userpicture'])) {
                $userobject->deletepicture = null;

                if ($user['userpicture'] == 0) {
                    $userobject->deletepicture = true;
                } else {
                    $userobject->imagefile = $user['userpicture'];
                }

                core_user::update_picture($userobject, $filemanageroptions);
            }

            // Update user interests.
            if (!empty($user['interests'])) {
                $trimmedinterests = array_map('trim', explode(',', $user['interests']));
                $interests = array_filter($trimmedinterests, function($value) {
                    return !empty($value);
                });
                useredit_update_interests($userobject, $interests);
            }

            // Update user custom fields.
            if (!empty($user['customfields'])) {

                foreach ($user['customfields'] as $customfield) {
                    // Profile_save_data() saves profile file it's expecting a user with the correct id,
                    // and custom field to be named profile_field_"shortname".
                    $user["profile_field_".$customfield['type']] = $customfield['value'];
                }
                profile_save_data((object) $user);
            }

            // Trigger event.
            \core\event\user_updated::create_from_userid($user['id'])->trigger();

            // Preferences.
            if (!empty($user['preferences'])) {
                $userpref = clone($existinguser);
                foreach ($user['preferences'] as $preference) {
                    $userpref->{'preference_'.$preference['type']} = $preference['value'];
                }
                useredit_update_user_preference($userpref);
            }
            if (isset($user['suspended']) and $user['suspended']) {
                \core\session\manager::kill_user_sessions($user['id']);
            }
        }

        $transaction->allow_commit();

        return null;
    }

    /**
     * Returns description of method result value
     *
     * @return null
     * @since Moodle 2.2
     */
    public static function update_users_returns() {
        return null;
    }


    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function hello_world_parameters() {
        return new external_function_parameters(
                array('welcomemessage' => new external_value(PARAM_TEXT, 'The welcome message. By default it is "Hello world,"', VALUE_DEFAULT, 'Hello world, '))
        );
    }

    /**
     * Returns welcome message
     * @return string welcome message
     */
    public static function hello_world($welcomemessage = 'Hello world, ') {
        global $USER;

        //Parameter validation
        //REQUIRED
        $params = self::validate_parameters(self::hello_world_parameters(),
                array('welcomemessage' => $welcomemessage));

        //Context validation
        //OPTIONAL but in most web service it should present
        $context = get_context_instance(CONTEXT_USER, $USER->id);
        self::validate_context($context);

        //Capability checking
        //OPTIONAL but in most web service it should present
        if (!has_capability('moodle/user:viewdetails', $context)) {
            throw new moodle_exception('cannotviewprofile');
        }

        return $params['welcomemessage'] . $USER->firstname ;;
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function hello_world_returns() {
        return new external_value(PARAM_TEXT, 'The welcome message + user first name');
    }


    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function seed_course_parameters() {
        return new external_function_parameters(
            array(
                'shortname_course' => new external_value(PARAM_TEXT, 'course module id'),
                'shortname_parent' => new external_value(PARAM_TEXT, 'user id'),
            )
        );
    }

    /**
     * Returns welcome message
     * @return string welcome message
     */
    public static function seed_course($shortname_course, $shortname_parent) {
        global $DB;

        $params = self::validate_parameters(
            self::seed_course_parameters(),
            array('shortname_course' => $shortname_course, 'shortname_parent' => $shortname_parent )
        );

        $shortname_course = $params['shortname_course'];
        $shortname_parent = $params['shortname_parent'];
        if($shortname_course == '' || $shortname_parent == ''){
            $result = array();
            $result['status'] = false;

            return $result;
        }

        $DB->insert_record('local_wslidera_seeds',[
            'shortname_course' => $shortname_course,
            'shortname_parent' => $shortname_parent,
            'status' => 0,
            'timemodified' => time(),
            'timecreated' => time(),
        ]);

        $result = array();
        $result['status'] = true;

        return $result;
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function seed_course_returns() {
        return new external_single_structure(
            array(
                'status'    => new external_value(PARAM_BOOL, 'status, true if success'),
            )
        );
    }




}
