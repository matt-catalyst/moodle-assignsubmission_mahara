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
 * This file contains the moodle hooks for the submission Mahara plugin
 *
 * @package    assignsubmission_mahara
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $USER, $DB, $CFG;

require_once("../../../../config.php");
require_once($CFG->dirroot.'/mod/lti/lib.php');
require_once($CFG->dirroot.'/mod/lti/locallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');


function mahara_assignsubmission_get_config ($id, $setting) {
    global $DB;

    $dbparams = array('assignment' => $id,
                      'plugin' => 'mahara',
                      'subtype' => 'assignsubmission',
                      'name' => $setting);
    $result = $DB->get_record('assign_plugin_config', $dbparams, '*', IGNORE_MISSING);
    if ($result) {
        return $result->value;
    }
    return false;
}

$id = required_param('id', PARAM_INT); // assignment id
$target = required_param('url', PARAM_URL); // Mahara view launch

list ($course, $cm) = get_course_and_cm_from_cmid($id, 'assign');

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/assign:view', $context);

$url = new \moodle_url('/mod/assign/view.php', array('id' => $id, 'sesskey' => sesskey()));
$returnurl = $url->out(false);
$urlparts = parse_url($CFG->wwwroot);

// determine the Mahara field and the username value
$username_attribute = mahara_assignsubmission_get_config($cm->instance, 'username_attribute');
$remoteuser = mahara_assignsubmission_get_config($cm->instance, 'remoteuser');
$username = (!empty($CFG->mahara_test_user) ? $CFG->mahara_test_user : $USER->{$username_attribute});
$field = 
// now the trump all - we actually want to test against the istitutions auth instances remoteuser.
    ($remoteuser ? 
     'remoteuser' : 
// else idnumber maps to studentid
     ($username_attribute == 'idnumber' ?
     'studentid' : 
// else the same attribute name in Mahara
     $username_attribute));

$requestparams = array(
        'resource_link_title' => $cm->name,
        'resource_link_description' => $cm->name,
        'user_id' => $USER->id,
        'lis_person_sourcedid' => $USER->idnumber,
        'roles' => 'Viewer',
        'context_id' => $course->id,
        'context_label' => $course->shortname,
        'context_title' => $course->fullname,
        'resource_link_id' => $target,
        'context_type' => 'CourseSection',
        'lis_person_contact_email_primary' => $USER->email,
        'lis_person_name_given' => $USER->firstname,
        'lis_person_name_family' => $USER->lastname,
        'lis_person_name_full' => $USER->firstname . ' ' . $USER->lastname,
        'ext_user_username' => $field.':'.$username,
        'launch_presentation_return_url' => $returnurl,
        'launch_presentation_locale' => current_language(),
        'ext_lms' => 'moodle-2',
        'tool_consumer_info_product_family_code' => 'moodle',
        'tool_consumer_info_version' => strval($CFG->version),
        'oauth_callback' => 'about:blank',
        'lti_version' => 'LTI-1p0',
        'lti_message_type' => 'basic-lti-launch-request',
        "tool_consumer_instance_guid" => $urlparts['host'],
        'tool_consumer_instance_name' => get_site()->fullname,
        'wsfunction' => 'mahara_autologin_redirect',
        );


$endpoint = mahara_assignsubmission_get_config($cm->instance, 'url');
$endpoint = $endpoint.(preg_match('/\/$/', $endpoint) ? '' : '/').'webservice/rest/server.php';
$key = mahara_assignsubmission_get_config($cm->instance, 'key');
$secret = mahara_assignsubmission_get_config($cm->instance, 'secret');

$parms = lti_sign_parameters($requestparams, $endpoint, "POST", $key, $secret);
$debuglaunch = mahara_assignsubmission_get_config($cm->instance, 'debug');
if ($debuglaunch) {
    $parms['ext_submit'] = 'Launch';
}
$endpointurl = new \moodle_url($endpoint);
$endpointparams = $endpointurl->params();
$content = lti_post_launch_html($parms, $endpoint, $debuglaunch);

echo $content;

