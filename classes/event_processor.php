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

/**
 * Process trigger system events.
 *
 * @package     local_wslidera
 * @copyright   Jair Revilla <jrevilla492@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace local_wslidera;

//se local_wslidera\helper\processor_helper;
//use local_wslidera\task\process_workflows;

defined('MOODLE_INTERNAL') || die();

/**
 * Process trigger system events.
 *
 * @package     local_wslidera
 * @copyright   Jair Revilla <jrevilla492@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_processor {
    //use processor_helper;


    /** @var  static a reference to an instance of this class (using late static binding). */
    protected static $singleton;

    /**
     * The observer monitoring all the events.
     *
     * @param \core\event\base $event event object.
     * @return bool
     */
    public static function process_event(\core\event\base $event) {

        if (empty(self::$singleton)) {
            self::$singleton = new self();
        }

        // Check whether this an event we're subscribed to,
        // and run the appropriate workflow(s) if so.
        self::$singleton->write($event);

        return false;

    }

    /**
     * We need to capture current info at this moment,
     * at the same time this lowers memory use because
     * snapshots and custom objects may be garbage collected.
     *
     * @param \core\event\base $event The event.
     * @return array $entry The event entry.
     */
    private function prepare_event($event) {
        global $PAGE, $USER;

        $entry = $event->get_data();
        $entry['origin'] = $PAGE->requestorigin;
        $entry['ip'] = $PAGE->requestip;
        $entry['realuserid'] = \core\session\manager::is_loggedinas() ? $USER->realuser : null;
        $entry['other'] = serialize($entry['other']);

        return $entry;
    }

    /**
     * Write event in the store with buffering. Method insert_event_entries() must be
     * defined.
     *
     * @param \core\event\base $event
     *
     * @return void
     */
    public function write(\core\event\base $event) {
        global $DB,$USER,$CFG;
        $entry = $this->prepare_event($event);
        if (!$this->is_event_ignored($event)) {
            
            $data = $event->get_data();
            switch((string)$data['eventname']){
                case "\\core\\event\\role_assigned":
                    $this->event_role_assigned($data);
                    break;
                case "\\core\\event\\user_graded":
                    $this->event_user_graded($data);
                    break;
                case "\\mod_zoom\\event\\join_meeting_button_clicked";
                    $this->event_join_meeting($data);
                    break;

            }


        }


    }

    private function event_join_meeting($data){
        global $DB;


        $course = get_course($data['courseid']);
        $user = $DB->get_record('user',['id' => $data['userid']]);
        
        $now = \DateTime::createFromFormat('U.u', microtime(true));

        $eventid = '1';
        if($data['other']['userishost'] != 1){
            $eventid = '2';
        }
        $send = [
            'idnumber' => $course->idnumber,
            'eventid' => $eventid,
            'eventdate' => $now->format("Y-m-d") . "T" . $now->format("H:i:s.u") . "Z",
            'username' => $user->username,
        ];

        $token = $this->create_mulesoft_token();

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->get_url_mulesoft('/interfaz_moodle/registrar_eventos'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($send),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

    }

    private function event_role_assigned($data){
        global $DB,$USER,$CFG;
        if($data['objectid'] == 3){

            $user = $DB->get_record_sql("
                SELECT 
                    u.id,
                    u.firstname,
                    u.lastname,
                    uid.data as email 
                FROM {user} u
                INNER JOIN {user_info_data} uid ON uid.userid = u.id
                INNER JOIN {user_info_field} uif ON uif.id = uid.fieldid
                WHERE u.id = :relateduserid AND uif.shortname = 'zoomuser'
                ",['relateduserid' => $data['relateduserid']]);
            if(!$user){
                $user = $DB->get_record('user', ['id' => $data['relateduserid']]);
            }
            $send = [
                "action" => "custCreate",
                "user_info" => [
                    "email" => $user->email,
                    "type" => 2,
                    "first_name" => $user->firstname,
                    "last_name" => $user->lastname,
                ],
            ];

            

            $token = $this->create_mulesoft_token();

            $curl = curl_init();

            curl_setopt_array($curl, array(
            CURLOPT_URL => $this->get_url_mulesoft('/interfaz_moodle/registrarUsuarioZoom'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($send),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            
            $cour = get_course($data['courseid']);
            $zooms = $DB->get_records('zoom',['course' => $cour->id]);
            require_once($CFG->dirroot.'/mod/zoom/classes/webservice.php');
            $service = new \mod_zoom_webservice();

            foreach($zooms as $zoom){
                
                if($zoom->alternative_hosts == ''){
                    $alternative_hosts = [];
                }else{
                    $alternative_hosts = explode(',',$zoom->alternative_hosts);
                }
                
                if(array_search($user->email,$alternative_hosts) == null){
                    $alternative_hosts[] = $user->email;
                }

                $alternative_hosts = implode(',',$alternative_hosts);
                $zoom->alternative_hosts = $alternative_hosts;

                $DB->update_record('zoom', $zoom);

                try{
                    $service->update_meeting($zoom);
                }catch(exception $e){
                    var_dump($e);
                }

            }
            
        }
    }

    private function event_user_graded($data){
        global $DB,$USER,$CFG;


            $dat = $DB->get_record_sql("SELECT 
                                        gi.id,
                                        gi.idnumber as name,
                                        ROUND(gg.finalgrade,2) as finalgrade,
                                        c.fullname,
                                        c.shortname,
                                        c.idnumber,
                                        u.username,
                                        gg.timemodified as trandate 
                                    FROM {grade_grades} gg
                                    INNER JOIN {grade_items} gi ON gi.id = gg.itemid
                                    INNER JOIN {course} c ON c.id = gi.courseid
                                    INNER JOIN {user} u ON u.id = gg.userid
                                    WHERE gg.id = :objectid",
                            ['objectid' => $data["objectid"]]);

            if($dat->name != ""){
                $dat->trandate = $dat->trandate + (5 * 3600);
                $send = [
                    'username' => $dat->username,
                    'idnumber' => $dat->idnumber,
                    'name' => $dat->name,
                    'grade' => $dat->finalgrade,
                    'trandate' => date("Y-m-d",$dat->trandate) . "T" . date("H:i:s",$dat->trandate) . "Z"
                ];

                
                $token = $this->create_mulesoft_token();

                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $this->get_url_mulesoft('/interfaz_moodle/registrarNotas'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode($send),
                    CURLOPT_HTTPHEADER => array(
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json'
                    ),
                ));

                $response = curl_exec($curl);

                curl_close($curl);
            }

    }

    private function create_mulesoft_token(){
        $curl = \curl_init();

        \curl_setopt_array($curl, array(
            CURLOPT_URL => $this->get_url_mulesoft('/seguridad/oauth2/token'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
                "client_id": "ghnpiczkdcutl7edslgsbmxilufng8zq.app.com",
                "client_secret": "uqee95jed7j7rnphr7vkwibwsm6d5brv"
            }',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer'
            ),
        ));

        $response = \curl_exec($curl);
        $token = \json_decode($response);
        return $token->access_token;
    }

    /**
     * The \tool_log\helper\buffered_writer trait uses this to decide whether
     * or not to record an event.
     *
     * @param \core\event\base $event
     * @return boolean
     */
    protected function is_event_ignored(\core\event\base $event) {
        global $DB;

        // Check if we can return these from cache.
        $cache = \cache::make('local_wslidera', 'eventsubscriptions');


        $sitesubscriptions = $cache->get(0);
        // If we do not have the triggers in the cache then return them from the DB.
        if ($sitesubscriptions === false) {
            
            $sitesubscriptions['\core\event\user_graded'] = true;
            $sitesubscriptions['\mod_zoom\event\join_meeting_button_clicked'] = true;
            $sitesubscriptions['\core\event\role_assigned'] = true;
            
            
            $cache->set(0, $sitesubscriptions);
        }

        // Check if a subscription exists for this event.
        if (isset($sitesubscriptions[$event->eventname])) {
            return false;
        }

        return true;
    }

    private function  get_url_mulesoft($path){
        return "https://mulesoft.isil.pe" . $path;
    }


}
