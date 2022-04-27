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
 * Web service local plugin template external functions and service definitions.
 *
 * @package    localwstemplate
 * @copyright  2011 Jerome Mouneyrac
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// We defined the web service functions to install.
$functions = array(
    'local_wslidera_hello_world' => array(
        'classname' => 'local_wslidera_external',
        'methodname' => 'hello_world',
        'classpath' => 'local/wslidera/externallib.php',
        'description' => 'Return Hello World FIRSTNAME. Can change the text (Hello World) sending a new text as parameter',
        'type' => 'read',
    ),
    'local_wslidera_update_users' => array(
        'classname' => 'local_wslidera_external',
        'methodname' => 'update_users',
        'classpath' => 'local/wslidera/externallib.php',
        'description' => 'Update status completions for activities',
        'type' => 'write',
    ),
    'local_wslidera_seed_course' => array(
        'classname' => 'local_wslidera_external',
        'methodname' => 'seed_course',
        'classpath' => 'local/wslidera/externallib.php',
        'description' => 'Get course advance by user',
        'type' => 'write',
    ),
    'local_wslidera_enrol_users' => array(
        'classname' => 'local_wslidera_external',
        'methodname' => 'enrol_users',
        'classpath' => 'local/wslidera/externallib.php',
        'description' => 'Get course advance by user',
        'type' => 'write',
    ),

);

// We define the services to install as pre-build services. A pre-build service is not editable by administrator.
$services = array(
    'Lidera WS 2021' => array(
        'functions' => array(
            'local_wslidera_hello_world',
            'local_wslidera_update_users',
            'local_wslidera_seed_course',
            'local_wslidera_enrol_users',
        ),
        'restrictedusers' => 0,
        'enabled' => 1,
        'downloadfiles'=>1,
    )
);
