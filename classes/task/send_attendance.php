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
class send_attendance extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('send_attendance_cron', 'local_wslidera');
    }

    /**
     * Run assignment cron.
     */
    public function execute() {
        global $CFG,$DB;

        $max_trant = $DB->get_record_sql("SELECT 
                    MAX(trandate) as maxdate
                FROM {local_wslidera_attendance}");

        if(!is_object($max_trant)){
            $max_trant = (object)['maxdate' => 0];
        }

        if($max_trant->maxdate == null ||$max_trant->maxdate == '' ){
            $max_trant->maxdate = 0;
        }

        mtrace("minimoooo = " . \json_encode($max_trant));
        mtrace("fecha: " . date('d-m-Y H:i:s', $max_trant->maxdate));
        $sql = "SELECT 
                    zmp.*, u.username,c.idnumber, zmd.start_time, zmd.meeting_id as mid
                FROM {zoom_meeting_participants} zmp
                INNER JOIN {user} u ON u.id = zmp.userid 
                INNER JOIN {zoom_meeting_details} zmd ON zmd.id = zmp.detailsid
                INNER JOIN {zoom} z ON z.id = zmd.zoomid
                INNER JOIN {course} c ON c.id = z.course
                WHERE zmd.end_time > :maxdate
                ORDER BY zmp.join_time ASC,zmp.userid ASC";

        $users = $DB->get_records_sql($sql.['maxdate' => $max_trant->maxdate]);


        $ultimate_users = [];
        
        $token = $this->get_token_mulesoft();

        $all_tmp = [];
        mtrace("Encontré " . count($users) . " registros.");
        foreach($users as $k => $u){
            $beginOfDay = strtotime("today", $u->start_time);
            $endOfDay   = strtotime("tomorrow", $beginOfDay) - 1;

            $all_meetings = $DB->get_records_sql(
                "SELECT 
                    zmp.*, u.username,c.idnumber, zmd.start_time, zmd.meeting_id as mid
                FROM {zoom_meeting_participants} zmp
                INNER JOIN {user} u ON u.id = zmp.userid 
                INNER JOIN {zoom_meeting_details} zmd ON zmd.id = zmp.detailsid
                INNER JOIN {zoom} z ON z.id = zmd.zoomid
                INNER JOIN {course} c ON c.id = z.course
                WHERE zmd.meeting_id = ':mid'
                AND zmd.start_time >= :beginOfDay
                AND zmd.start_time <= :endOfDay
                AND zmp.id NOT IN (:users)
                ORDER BY zmp.join_time ASC,zmp.userid ASC",
                [
                    'mid' => $u->mid,
                    'beginOfDay' => $beginOfDay,
                    'endOfDay' => $endOfDay,
                    'users' => implode(',',array_keys($users))
                ]
            );


            $all_tmp[$k] = $u;
            $all_tmp = array_merge($all_tmp,$all_meetings);
        }

        $users = $all_tmp;
        unset($all_tmp);

        $envios = 1;
        foreach ($users as $u){

            $finalKey = $u->username . 
                        '-' . 
                        $u->idnumber . 
                        '-' . 
                        $u->mid . 
                        '-' . 
                        date('d-m-Y',$u->start_time);
                        if($u->idnumber == '1264.202200' && 
                        $u->username == '09159442@carbon.super'){
                            mtrace('Encontramos esto!!');
                            mtrace('id: ' . $u->id );
                            mtrace('mindate: ' . date('Y-m-d h:i:s a',$u->join_time) );
                            mtrace('maxdate: ' . date('Y-m-d h:i:s a',$u->leave_time));
                            mtrace('duration: '. $u->duration);
                    }

            if(isset($ultimate_users[$finalKey])){
                if($ultimate_users[$finalKey]->mindate > $u->join_time){
                    $ultimate_users[$finalKey]->mindate = $u->join_time;
                }

                if($ultimate_users[$finalKey]->maxdate < $u->leave_time){
                    $ultimate_users[$finalKey]->maxdate = $u->leave_time;
                }
                $ultimate_users[$finalKey]->attendance += $u->duration;
            }else{
                $ultimate_users[$finalKey] = (object)[
                    'idnumber' => $u->idnumber,
                    'username' => $u->username,
                    'mindate' => $u->join_time,
                    'maxdate' => $u->leave_time,
                    'attendance' => $u->duration,
                    'trandate' => $u->start_time,
                ];
            }

            
        }

        unset($u);
        unset($users);
        foreach ($ultimate_users as $user){
            

            $durationremainder = $user->attendance % 60;
            if ($durationremainder != 0) {
                $user->attendance += 60 - $durationremainder;
            }
            $durationremainder = round($user->attendance / 60);
            $user->mindate = $user->mindate + (5 * 3600);
            $user->maxdate = $user->maxdate + (5 * 3600);
            $insert_user = (object)[
                'idnumber' => $user->idnumber,
                'username' => $user->username,
                'mindate' => date('Y-m-d',$user->mindate) . 'T' . date('H:i:s',$user->mindate) . 'Z',
                'maxdate' => date('Y-m-d',$user->maxdate) . 'T' . date('H:i:s',$user->maxdate) . 'Z',
                'attendance' => $durationremainder,
                //'trandate' => date('Y-m-d',$user->trandate) . 'T00:00:00Z',
                'trandate' => date('Y-m-d',(time() + (5 * 3600))) . 'T' . date('H:i:s',(time() + (5 * 3600))) . 'Z',
            ];


            
            mtrace('consultando!');
            $data = $DB->get_record('local_wslidera_attendance',(array)$user);
            if(!is_object($data)){
                mtrace('insertando assitencia en la tabla!');
                $user->timecreated = time();
                $user->timemodified = time();
                mtrace("insertar assitencia = " . \json_encode($user));
                
                $DB->insert_record('local_wslidera_attendance',$user);
                
            }else{
                mtrace('NOOOOO en la tabla!');
            }

            $user_json = \json_encode($insert_user);
            mtrace('enviando assitencia!');
            mtrace($user_json);

            
            $curl = \curl_init();

            \curl_setopt_array($curl, array(
                CURLOPT_URL => $this->get_path_mulesoft('/interfaz_moodle/registrarAsistencia'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $user_json,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ),
            ));
            
            
            $response = \curl_exec($curl);

            \curl_close($curl);

            mtrace("response = " . $response);
            $envios++;
            
            
        }

        mtrace("enviados en total " . $envios . " registros.");


        return true;
    }

    private function get_token_mulesoft(){
        $curl = \curl_init();

        \curl_setopt_array($curl, array(
            CURLOPT_URL => $this->get_path_mulesoft('/seguridad/oauth2/token'),
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
        $token = $token->access_token;
        \curl_close($curl);
        return $token;
    }

    private function get_path_mulesoft($path){
        return 'https://mulesoft.isil.pe';
    }
}
