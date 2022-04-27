<?php
// This file is part of Moodle - http://moodle.org/
//
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

namespace local_wslidera\task;
defined('MOODLE_INTERNAL') || die();

/**
 * A schedule task for assignment cron.
 *
 * @package   local_wslidera
 * @copyright 2021 Jair Revilla <j@nuxtu.la>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seed_course extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('seed_course_cron', 'local_wslidera');
    }

    /**
     * Run assignment cron.
     */
    public function execute() {
        global $CFG,$DB;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');

        $seeds = $DB->get_records_sql('SELECT 
                                            {local_wslidera_seeds}.*,
                                            (SELECT {user}.email FROM {course}
                                                INNER JOIN {context} ON {context}.instanceid = {course}.id
                                                INNER JOIN {role_assignments} ON {context}.id = {role_assignments}.contextid
                                                INNER JOIN {role} ON {role}.id = {role_assignments}.roleid
                                                INNER JOIN {user} ON {user}.id = {role_assignments}.userid
                                                WHERE {role}.id = 3 AND 
                                                {course}.shortname = {local_wslidera_seeds}.shortname_course LIMIT 1 ) as teacher
                                        FROM {local_wslidera_seeds} 
                                        WHERE {local_wslidera_seeds}.status = 0 
                                        AND (SELECT {user}.email FROM {course}
                                                INNER JOIN {context} ON {context}.instanceid = {course}.id
                                                INNER JOIN {role_assignments} ON {context}.id = {role_assignments}.contextid
                                                INNER JOIN {role} ON {role}.id = {role_assignments}.roleid
                                                INNER JOIN {user} ON {user}.id = {role_assignments}.userid
                                                WHERE {role}.id = 3 AND 
                                                {course}.shortname = {local_wslidera_seeds}.shortname_course LIMIT 1 ) IS NOT NULL
                                        LIMIT 50');

        foreach($seeds as  $vs){
            mtrace("Curso Padre:" . $vs->shortname_parent);
            mtrace("Curso Hijo:" . $vs->shortname_course);
            $shortname = $vs->shortname_parent;
            $shortname_new = $vs->shortname_course;
            $newcourseid  = $DB->get_record('course',['shortname' => $shortname_new]);

            if(!$newcourseid){
                mtrace('No existe el curso a duplicar: ' . $shortname_new);
               continue;
            }

            $sql_users = 	"SELECT {user}.id, {user}.email FROM {course}
						INNER JOIN {context} ON {context}.instanceid = {course}.id
						INNER JOIN {role_assignments} ON {context}.id = {role_assignments}.contextid
						INNER JOIN {role} ON {role}.id = {role_assignments}.roleid
						INNER JOIN {user} ON {user}.id = {role_assignments}.userid
						WHERE {role}.id = 3 AND 
						{course}.id = " . $newcourseid->id;

            $teachers = array_values($DB->get_records_sql($sql_users));
            if($teachers == []){
                mtrace('No tiene profesor el curso ' . $newcourseid->shortname);
                continue;
            }

            // Context validation.
            if (! ($course = $DB->get_record('course', array('shortname' => $shortname )))) {
                mtrace('No existe el curso a Padre: ' . $shortname);
                continue;
            }


            $backupdefaults = array(
                'activities' => 1,
                'blocks' => 1,
                'filters' => 1,
                'users' => 1,
                'enrolments' => \backup::ENROL_WITHUSERS,
                'role_assignments' => 0,
                'comments' => 0,
                'userscompletion' => 0,
                'logs' => 0,
                'grade_histories' => 0
            );

            $backupsettings = array();
            // Check for backup and restore options.
            if (!empty($params['options'])) {
                foreach ($params['options'] as $option) {

                    // Strict check for a correct value (allways 1 or 0, true or false).
                    $value = clean_param($option['value'], PARAM_INT);

                    if ($value !== 0 and $value !== 1) {
                        throw new moodle_exception('invalidextparam', 'webservice', '', $option['name']);
                    }

                    if (!isset($backupdefaults[$option['name']])) {
                        throw new moodle_exception('invalidextparam', 'webservice', '', $option['name']);
                    }

                    $backupsettings[$option['name']] = $value;
                }
            }

            // Backup the course.

            $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id, \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, 2);

            foreach ($backupsettings as $name => $value) {
                if ($setting = $bc->get_plan()->get_setting($name)) {
                    $bc->get_plan()->get_setting($name)->set_value($value);
                }
            }

            $backupid       = $bc->get_backupid();
            $backupbasepath = $bc->get_plan()->get_basepath();

            $bc->execute_plan();
            $results = $bc->get_results();
            $file = $results['backup_destination'];

            $bc->destroy();

            // Restore the backup immediately.

            // Check if we need to unzip the file because the backup temp dir does not contains backup files.
            if (!file_exists($backupbasepath . "/moodle_backup.xml")) {
                $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);
            }

            $rc = new \restore_controller($backupid, $newcourseid->id,
                \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, 2, \backup::TARGET_NEW_COURSE);


            if (!$rc->execute_precheck()) {
                $precheckresults = $rc->get_precheck_results();
                if (is_array($precheckresults) && !empty($precheckresults['errors'])) {
                    if (empty($CFG->keeptempdirectoriesonbackup)) {
                        fulldelete($backupbasepath);
                    }

                    $errorinfo = '';

                    foreach ($precheckresults['errors'] as $error) {
                        $errorinfo .= $error;
                    }

                    if (array_key_exists('warnings', $precheckresults)) {
                        foreach ($precheckresults['warnings'] as $warning) {
                            $errorinfo .= $warning;
                        }
                    }
                    throw new moodle_exception('backupprecheckerrors', 'webservice', '', $errorinfo);
                }
            }

            $rc->execute_plan();
            $rc->destroy();

            $course = $DB->get_record('course', array('id' => $newcourseid->id), '*', MUST_EXIST);
            $course->fullname = $newcourseid->fullname;
            $course->shortname = $newcourseid->shortname;
            $course->idnumber = $newcourseid->idnumber;
            $course->visible = 1;

            // Set shortname and fullname back.
            $DB->update_record('course', $course);
            mtrace('Actualizando Actividades zoom ');
            $service = new \mod_zoom_webservice();

            $zooms = $DB->get_records_sql('SELECT 
                                                    z.* 
                                                FROM {course} c
                                                INNER JOIN {zoom} z ON z.course = c.id
                                                WHERE c.id = ' . $course->id);
            foreach($zooms as $zoom){
                mtrace('Zoom> de ' . $zoom->name . ' a ' .  $course->idnumber . ' - ' . $zoom->name);
                $zoom->name = $course->idnumber . ' - ' . $zoom->name;
                $service->update_meeting($zoom);
                mtrace($zoom->name);
                $DB->update_record('zoom', $zoom);
            }


            if (empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($backupbasepath);
            }

            // Delete the course backup file created by this WebService. Originally located in the course backups area.
            $file->delete();

            $vs->status = 1;
            $DB->update_record('local_wslidera_seeds',$vs);
        }

        purge_caches();
        return true;
    }
}
