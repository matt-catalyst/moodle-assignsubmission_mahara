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
 * This file contains the definition for the library class for Mahara submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package    assignsubmission_mahara
 * @copyright  2012 Lancaster University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/oauthlib.php');
class mahara_oauth extends oauth_helper {

    /**
     * Request oauth protected resources
     * @param string $method
     * @param string $url
     * @param string $token
     * @param string $secret
     */
    public function request($method, $url, $params=array(), $token='', $secret='') {
        $token = '';
        $this->sign_secret = $secret.'&'.$token;  // we never pass the toekn, only the secret
        if (strtolower($method) === 'post' && !empty($params)) {
            $oauth_params = $this->prepare_oauth_parameters($url, array('oauth_token'=>$token) + $params, $method);
        } else {
            $oauth_params = $this->prepare_oauth_parameters($url, array('oauth_token'=>$token), $method);
        }
        $this->setup_oauth_http_header($oauth_params);
        $content = call_user_func_array(array($this->http, strtolower($method)), array($url, $params, $this->http_options));
        // reset http header and options to prepare for the next request
        $this->http->resetHeader();
        // return request return value
        return $content;
    }
}

/**
 * library class for Mahara submission plugin extending submission plugin base class
 *
 * @package    assignsubmission_mahara
 * @copyright  2012 Lancaster University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_mahara extends assign_submission_plugin {

    // We've selected the page/collection, but we haven't locked it or issued a special access token
    const STATUS_SELECTED = 'selected';

    // We've locked the page in Mahara and issued an access token
    const STATUS_SUBMITTED = 'submitted';

    // We locked and then unlocked the page in Mahara, which means we probably still have a valid
    // access token for it
    const STATUS_RELEASED = 'released';

    /**
     * Get the name of the Mahara submission plugin
     *
     * @return string
     */
    public function get_name() {
        return get_string('mahara', 'assignsubmission_mahara');
    }

   /**
    * Get Mahara submission information from the database
    *
    * @global stdClass $DB
    * @param  int $submissionid
    * @return mixed
    */
    private function get_mahara_submission($submissionid) {
        global $DB;

        return $DB->get_record('assignsubmission_mahara', array('submission'=>$submissionid));
    }

    /**
     * Get the settings form for Mahara submission plugin
     *
     * @global stdClass $CFG
     * @global stdClass $DB
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/submission/mahara/lib.php');

        $mform->addElement('text', 'assignsubmission_mahara_url', get_string('url', 'assignsubmission_mahara'), array('maxlength' => 255, 'size' => 50));
        $mform->setType('assignsubmission_mahara_url', PARAM_RAW_TRIMMED);
        $mform->setDefault('assignsubmission_mahara_url', $this->get_config('url'));
        $mform->disabledIf('assignsubmission_mahara_url', 'assignsubmission_mahara_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_mahara_key', get_string('key', 'assignsubmission_mahara'), array('maxlength' => 255, 'size' => 50));
        $mform->setType('assignsubmission_mahara_key', PARAM_RAW_TRIMMED);
        $mform->setDefault('assignsubmission_mahara_key', $this->get_config('key'));
        $mform->disabledIf('assignsubmission_mahara_key', 'assignsubmission_mahara_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_mahara_secret', get_string('secret', 'assignsubmission_mahara'), array('maxlength' => 255, 'size' => 50));
        $mform->setType('assignsubmission_mahara_secret', PARAM_RAW_TRIMMED);
        $mform->setDefault('assignsubmission_mahara_secret', $this->get_config('secret'));
        $mform->disabledIf('assignsubmission_mahara_secret', 'assignsubmission_mahara_enabled', 'notchecked');

        $locked = $this->get_config('lock');
        if ($locked === false) {
            // No setting for this instance, so use the sitewide default
            $locked = get_config('assignsubmission_mahara', 'lock');
        }

        // Menu to select whether to lock Mahara pages or not
        $locksettings = array(
            ASSIGNSUBMISSION_MAHARA_SETTING_DONTLOCK => new lang_string('no'),
            ASSIGNSUBMISSION_MAHARA_SETTING_KEEPLOCKED => new lang_string('yeskeeplocked', 'assignsubmission_mahara'),
            ASSIGNSUBMISSION_MAHARA_SETTING_UNLOCK => new lang_string('yesunlock', 'assignsubmission_mahara')
        );
        $mform->addElement('select', 'assignsubmission_mahara_lockpages', get_string('lockpages', 'assignsubmission_mahara'), $locksettings);
        $mform->setDefault('assignsubmission_mahara_lockpages', $locked);
        $mform->addHelpButton('assignsubmission_mahara_lockpages', 'lockpages', 'assignsubmission_mahara');
        $mform->disabledIf('assignsubmission_mahara_lockpages', 'assignsubmission_mahara_enabled', 'notchecked');

        $mform->addElement('checkbox', 'assignsubmission_mahara_debug', get_string('debug', 'assignsubmission_mahara'));
        $mform->setDefault('assignsubmission_mahara_debug', $this->get_config('debug'));

        $strrequired = get_string('required');
        $mform->addRule('assignsubmission_mahara_url', $strrequired, 'required', null, 'client');
        $mform->addRule('assignsubmission_mahara_key', $strrequired, 'required', null, 'client');
        $mform->addRule('assignsubmission_mahara_secret', $strrequired, 'required', null, 'client');

        $mform->addHelpButton('assignsubmission_mahara_url', 'url', 'assignsubmission_mahara');
        $mform->addHelpButton('assignsubmission_mahara_key', 'key', 'assignsubmission_mahara');
        $mform->addHelpButton('assignsubmission_mahara_secret', 'secret', 'assignsubmission_mahara');
        // Menu to select which user attribute to use as the remote username
        $username_attribute = $this->get_config('username_attribute');
        if ($username_attribute === false) {
            // No setting for this instance, so use the sitewide default
            $username_attribute = 'username';
        }
        $usernamesettings = array(
            'username' => new lang_string('username'),
            'idnumber' => new lang_string('idnumber'),
            'email' => new lang_string('email'),
        );
        $mform->addElement('select', 'assignsubmission_mahara_username_attribute', get_string('username_attribute', 'assignsubmission_mahara'), $usernamesettings);
        $mform->setDefault('assignsubmission_mahara_username_attribute', $username_attribute);
        $mform->addHelpButton('assignsubmission_mahara_username_attribute', 'username_attribute', 'assignsubmission_mahara');
        $mform->disabledIf('assignsubmission_mahara_username_attribute', 'assignsubmission_mahara_enabled', 'notchecked');
    }

    /**
     * Save the settings for Mahara plugin
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/submission/mahara/lib.php');

        $this->set_config('url', $data->assignsubmission_mahara_url);
        $this->set_config('key', $data->assignsubmission_mahara_key);
        $this->set_config('secret', $data->assignsubmission_mahara_secret);
        $this->set_config('debug', (isset($data->assignsubmission_mahara_debug) ? $data->assignsubmission_mahara_debug : false));
        $this->set_config('lock', $data->assignsubmission_mahara_lockpages);
        $this->set_config('username_attribute', $data->assignsubmission_mahara_username_attribute);

        // test Mahara connection
        try {
            $data = $this->webservice_call("mahara_user_get_extended_context", array());
            $funcs = array();
            $required = array("mahara_user_get_extended_context", "mahara_submission_get_views_for_user", "mahara_submission_submit_view_for_assessment", "mahara_submission_release_submitted_view");
            foreach ($data['functions'] as $v) {
                $funcs[]= $v['function'];
            }
            foreach ($required as $f) {
                if (!in_array($f, $funcs)) {
                    $this->set_error(get_string('errorinvalidurl', 'assignsubmission_mahara', 'missing functions: '.implode(", ", $required))."<br/>".get_string('invalidurlhelp', 'assignsubmission_mahara'));
                    return false;
                }
            }

        } catch (Exception $e) {
            $this->set_error(get_string('errorinvalidurl', 'assignsubmission_mahara', $e->getMessage())."<br/>".get_string('invalidurlhelp', 'assignsubmission_mahara'));
            return false;
        }

        return true;
    }



    /**
     * Add elements to user submission form
     *
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @param int $userid
     * @return bool
     */
    public function webservice_call($function, $params, $method="POST") {
        global $CFG;

        $endpoint = $this->get_config('url').(preg_match('/\/$/', $this->get_config('url')) ? '' : '/').'webservice/rest/server.php';
        $args = array(
            'oauth_consumer_key'=> $this->get_config('key'),
            'oauth_consumer_secret'=> $this->get_config('secret'),
            'oauth_callback' => 'about:blank',
            'api_root' => $endpoint,
        );

        $client = new mahara_oauth($args);
        if ($CFG->disablesslchecks) {
            $options = array('CURLOPT_SSL_VERIFYPEER' => 0, 'CURLOPT_SSL_VERIFYHOST' => 0);
            $client->setup_oauth_http_options($options);
        }
        // have to flatten nested parameters into JSON as OAuth can't handle it
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = json_encode($v);
            }
        }
        $content = $client->request($method, $endpoint,
                             array_merge($params, array('wsfunction' => $function, 'alt' => 'json')),
                             null,
                             $this->get_config('secret'));
        $data = json_decode($content, true);
        return $data;
    }


    /**
     * Add elements to user submission form
     *
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @param int $userid
     * @return bool
     */
    public function get_form_elements_for_user($submission, MoodleQuickForm $mform, stdClass $data, $userid) {
        global $DB, $PAGE, $CFG;

        $PAGE->requires->js('/mod/assign/submission/mahara/js/popup.js');
        $PAGE->requires->js('/mod/assign/submission/mahara/js/filter.js');
        // Getting submission.
        if ($submission) {
            $maharasubmission = $this->get_mahara_submission($submission->id);
        }
        if (!empty($maharasubmission)) {
            $selectedid = $maharasubmission->viewid;
            $selectediscollection = $maharasubmission->iscollection;
        }
        else {
            $selectedid = 0;
            $selectediscollection = null;
        }

        // Getting views (pages) user have in linked site.
        $views = $this->get_views();
        if (!$views) {
            $views = array(
                    'data' => array(),
                    'collections' => array('data' => array()),
                    'ids' => array(),
            );
        }

	    // Filter out collection views, special views, and already-submitted views (except the current one)
	    foreach ($views['data'] as $i => $view) {
	        if (
	                $view['collid']
	                || $view['type'] != 'portfolio'
	                || (
	                        $view['submittedtime']
	                        && !($view['id'] == $selectedid && $selectediscollection == false)
	                )
	        ) {
	            unset($views['ids'][$i]);
	            unset($views['data'][$i]);
	            $views['count']--;
	        }
	    }
	    // Filter out empty or submitted collections
	    foreach ($views['collections']['data'] as $i => $coll) {
	        if (
	                (
	                        array_key_exists('numviews', $coll)
	                        && $coll['numviews'] == 0
	                ) || (
	                        $coll['submittedtime']
	                        && !($coll['id'] == $selectedid && $selectediscollection == true)
	                )
	        ) {
	            unset($views['collections']['data'][$i]);
	            $views['collections']['count']--;
	        }
	    }
	    $viewids = $views['ids'];

        // Prepare the header.
        try {

            $remotehost = (object)$this->webservice_call("mahara_user_get_extended_context", array());
        } catch (Exception $e) {
            debugging("Remote host webservice call failed: ".$e->getCode().":".$e->getMessage());
            throw new moodle_exception('errorwsrequest', 'assignsubmission_mahara', '', $e->getMessage());
        }

        $remotehost->jumpurl = $remotehost->siteurl;
        $remotehost->name = $remotehost->sitename;

        // See if any of views are already in use, we will remove them from select.
        if (count($viewids) || count($views['collections']['data'])) {

            $mform->addElement('static', '', $remotehost->name, get_string('selectmaharaview', 'assignsubmission_mahara', $remotehost));

            // Add "none selected" option
            $mform->addElement('radio', 'viewid', '', get_string('noneselected', 'assignsubmission_mahara'), 'none');
            $mform->setType('viewid', PARAM_ALPHANUM);
            $mform->setDefault('viewid', 'none');

            $mform->addElement('text', 'search', get_string('search'));
            $mform->setType('search', PARAM_RAW);
            $mform->addElement('html', '<hr/><br/>');

            if (count($views['data'])) {
                $mform->addElement('static', 'view_by',
                    get_string('viewsby', 'assignsubmission_mahara', $views['displayname'])
                );
                foreach ($views['data'] as $view) {
                    $anchor = $this->get_preview_url($view['title'], $view['url'], strip_tags($view['description']));
                    $mform->addElement('radio', 'viewid', '', $anchor, 'v' . $view['id']);
                }
            }
            if (count($views['collections']['data'])) {
                $mform->addElement('static', 'collection_by',
                    get_string('collectionsby', 'assignsubmission_mahara', $views['displayname'])
                );
                foreach ($views['collections']['data'] as $coll) {
                    $anchor = $this->get_preview_url($coll['name'], $coll['url'], strip_tags($coll['description']));
                    $mform->addElement('radio', 'viewid', '', $anchor, 'c' . $coll['id']);
                }
            }
            if (!empty($maharasubmission)) {
                if ($maharasubmission->iscollection) {
                    $prefix = 'c';
                } else {
                    $prefix = 'v';
                }
                $mform->setDefault('viewid', $prefix . $maharasubmission->viewid);
            }

            return true;
        }
        else {
            $mform->addElement(
                    'static',
                    '',
                    $remotehost->name,
                    get_string('noviewscreated', 'assignsubmission_mahara', $remotehost)
            );
            $mform->addElement('hidden', 'viewid', 'none');
            $mform->setType('viewid', PARAM_ALPHANUM);
            return true;
        }

    }

    /**
     * Retrieve user views from Mahara portfolio.
     *
     * @global stdClass $USER
     * @param string $query Search query
     * @return mixed
     */
    public function get_views($query = '') {
        global $USER, $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/submission/mahara/lib.php');

        $username = (!empty($CFG->mahara_test_user) ? $CFG->mahara_test_user : $USER->{$this->get_config('username_attribute')});
        try {
            $result = $this->webservice_call("mahara_submission_get_views_for_user",
                                      array('users' => array( array('username' => $username,
                                                                    'query' => $query),)));
            $result = array_pop($result);
            // explode comma separated integer string
            $result['views']['ids'] = array_map('intval', explode(',', $result['views']['ids']));
            // overwrite url with full URL
            foreach ($result['views']['data'] as $key => $value) {
                $result['views']['data'][$key]['url'] = $result['views']['data'][$key]['fullurl'];
            }
           foreach ($result['views']['collections']['data'] as $key => $value) {
                $result['views']['collections']['data'][$key]['url'] = $result['views']['collections']['data'][$key]['fullurl'];
            }
        } catch (Exception $e) {
            debugging("Get views webservice call failed: ".$e->getCode().":".$e->getMessage());
            throw new moodle_exception('errorwsrequest', 'assignsubmission_mahara', '', $e->getMessage());
            // return array();
        }

        return $result['views'];
    }


    /**
     * Submit view or collection for assessment in Mahara. This marks the view/collection
     * as "submitted", creates an access token, and locks the view/collection from editing
     * or further submissions in Mahara.
     *
     * @global stdClass $USER
     * @param stdClass $submission The submission record (used for verification)
     * @param int $viewid Id of the view or collection to submit
     * @param boolean $iscollection True if it's a collection, False if not
     * @param $viewownermoodleid ID of the view ower's Moodle user record
     * @return mixed
     */
    public function submit_view($submission, $viewid, $iscollection, $viewownermoodleid = null) {
        global $USER, $DB, $CFG;

        // Verify that it's not already submitted to another Mahara assignment in this Moodle site.
        // We can't do this on the Mahara side, because Mahara only knows the remote site's wwwroot.
        if (
                $DB->record_exists_select(
                        'assignsubmission_mahara',
                        'viewid = ? AND iscollection = ? AND viewstatus = ? AND submission != ?',
                        array(
                                $viewid,
                                ($iscollection ? 1 : 0),
                                self::STATUS_SUBMITTED,
                                $submission->id
                        )
                )
        ) {
            throw new moodle_exception('errorvieworcollectionalreadysubmitted', 'assignsubmission_mahara');
        }

        if (!$viewownermoodleid) {
            $username = $USER->{$this->get_config('username_attribute')};
        }
        else {
            $username = $DB->get_field('user', $this->get_config('username_attribute'), array('id'=>$viewownermoodleid));
        }


        require_once($CFG->dirroot . '/mod/assign/submission/mahara/lib.php');
        $username = (!empty($CFG->mahara_test_user) ? $CFG->mahara_test_user : $username);
        try {
            $result  = $this->webservice_call(
                       'mahara_submission_submit_view_for_assessment',
                       array('views' => array( array('username' => $username,
                                                      'viewid' => $viewid,
                                                      'iscollection' => $iscollection,
                                                      'lock' => true,
                                                      'apilevel' => 'moodle-assignsubmission-mahara:2',
                                                      'wwwroot' => $CFG->wwwroot),))
                       );
            $result = array_pop($result);
        } catch (Exception $e) {
            debugging("Submit view for assessment webservice call failed: ".$e->getCode().":".$e->getMessage());
            throw new moodle_exception('errorwsrequest', 'assignsubmission_mahara', '', $e->getMessage());
        }
        return $result;
    }

    /**
     * Release submitted view for assessment.
     *
     * @global stdClass $USER
     * @param int $viewid View or Collection ID
     * @param array $viewoutcomes Outcomes data
     * @param boolean $iscollection Whether the $viewid is a view or a collection
     * @return mixed
     */
    public function release_submitted_view($viewid, $viewoutcomes, $iscollection = false) {
        global $USER;
        try {
            $username = $USER->username;
            $result  = $this->webservice_call(
                       'mahara_submission_release_submitted_view',
                       array('views' => array( array('username' => $username,
                                                      'viewid' => $viewid,
                                                      'iscollection' => $iscollection,
                                                      'viewoutcomes' => implode(',', $viewoutcomes),)))
                       );

        } catch (Exception $e) {
            debugging("Submit view for assessment webservice call failed: ".$e->getCode().":".$e->getMessage());
            throw new moodle_exception('errorwsrequest', 'assignsubmission_mahara', '', $e->getMessage());
        }
        return $result;
    }


     /**
      * Save submission data to the database
      *
      * @global stdClass $DB
      * @param stdClass $submission
      * @param stdClass $data
      * @return bool
      */
     public function save(stdClass $submission, stdClass $data) {
        global $USER, $DB;

        // Because the drop-down menu contains collections & views, we make the id
        // start with "v" or "c" to indicate the type, e.g. v30, c100
        if ($data->viewid == 'none') {
            $data->viewid = null;
        } else {
            $iscollection = ($data->viewid[0] == 'c');
            $data->viewid = substr($data->viewid, 1);
        }

        $maharasubmission = $this->get_mahara_submission($submission->id);
        if ($submission->status === ASSIGN_SUBMISSION_STATUS_DRAFT) {
            // Draft. All we need to do is just save or update submitted view data.
            if ($data->viewid === null) {
                // They selected "(nothing selected)", so remove their Mahara selection
                return $DB->delete_records(
                        'assignsubmission_mahara',
                        array('submission' => $submission->id)
                );
            }

            if (!$views = $this->get_views()) {
                // Wrap recorded error in language string and return false.
                $this->set_error(get_string('errorrequest', 'assignsubmission_mahara', $this->get_error()));
                return false;
            }

            if ($iscollection) {
                $foundcoll = false;
                if (!is_array($views['collections']['data'])) {
                    return false;
                }
                foreach ($views['collections']['data'] as $coll) {
                    if ($coll['id'] == $data->viewid) {
                        $foundcoll = true;
                        $url = $coll['url'];
                        $title = clean_text($coll['name']);
                        break;
                    }
                }
                // The submitted collection id isn't one of the allowed options for this user
                if (!$foundcoll) {
                    return false;
                }
            } else {
                $keys = array_flip($views['ids']);
                // The submitted view id isn't one of the allowed options for this user
                if (!array_key_exists($data->viewid, $keys)) {
                    return false;
                }
                $viewdata = $views['data'][$keys[$data->viewid]];
                $url = $viewdata['url'];
                $title = clean_text($viewdata['title']);
            }

            if ($maharasubmission) {
                $maharasubmission->viewid = $data->viewid;
                $maharasubmission->viewurl = $url;
                $maharasubmission->viewtitle = $title;
                $maharasubmission->iscollection = (int) $iscollection;
                $maharasubmission->viewstatus = self::STATUS_SELECTED;
                return $DB->update_record('assignsubmission_mahara', $maharasubmission);
            } else {
                $maharasubmission = new stdClass();
                $maharasubmission->viewid = $data->viewid;
                $maharasubmission->viewurl = $url;
                $maharasubmission->viewtitle = $title;
                $maharasubmission->iscollection = (int) $iscollection;
                $maharasubmission->viewstatus = self::STATUS_SELECTED;

                $maharasubmission->submission = $submission->id;
                $maharasubmission->assignment = $this->assignment->get_instance()->id;
                return $DB->insert_record('assignsubmission_mahara', $maharasubmission) > 0;
            }
        } else {
            // This is not the draft, but the actual submission. Process it properly.

            // If viewid is null, it means they selected no page.
            if ($data->viewid === null) {
                if ($maharasubmission) {
                    // Unlock the previously selected page
                    if ($maharasubmission->viewstatus == self::STATUS_SUBMITTED) {
                        $response = $this->release_submitted_view($maharasubmission->viewid, array(), $maharasubmission->iscollection);
                        if ($response === false) {
                            throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
                        }
                    }
                    // Delete the record of the previously selected page from our submission, and exit
                    return $DB->delete_records('assignsubmission_mahara', array('submission' => $submission->id));
                } else {
                    // No previously selected page to clear
                    return true;
                }
            }

            // Lock submission on mahara side.
            if (!$response = $this->submit_view($submission, $data->viewid, $iscollection)) {
                throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
            }

            // If we're not locking user pages, then immediately release the page. This will leave it unlocked,
            // but leave the access code in place.
            // TODO: Replace this hack with something more robust. It's an oversight and a security hole, that the
            // access code remains in place in Mahara when you release the page via XML-RPC.
            if (!$this->get_config('lock')) {
                $this->release_submitted_view($data->viewid, array(), $iscollection);
                $status = self::STATUS_RELEASED;
            } else {
                $status = self::STATUS_SUBMITTED;
            }


            $params = array(
                'context' => context_module::instance($this->assignment->get_course_module()->id),
                'courseid' => $this->assignment->get_course()->id,
                'objectid' => $submission->id,
                'other' => array(
                    'pathnamehashes' => array(),
                    'content' => ''
                )
            );
            if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
                $params['relateduserid'] = $submission->userid;
            }
            $event = \assignsubmission_mahara\event\assessable_uploaded::create($params);
            $event->trigger();

            $groupname = null;
            $groupid = 0;
            // Get the group name as other fields are not transcribed in the logs and this information is important.
            if (empty($submission->userid) && !empty($submission->groupid)) {
                $groupname = $DB->get_field('groups', 'name', array('id' => $submission->groupid), '*', MUST_EXIST);
                $groupid = $submission->groupid;
            } else {
                $params['relateduserid'] = $submission->userid;
            }

            // Unset the objectid and other field from params for use in submission events.
            unset($params['objectid']);
            unset($params['other']);
            $params['other'] = array(
                'submissionid' => $submission->id,
                'submissionattempt' => $submission->attemptnumber,
                'submissionstatus' => $submission->status,
                'groupid' => $groupid,
                'groupname' => $groupname
            );

            if ($maharasubmission) {
                // If we are updating previous submission, release previous submission first (if it's locked).
                if ($maharasubmission->viewid != $data->viewid && $maharasubmission->viewstatus == self::STATUS_SUBMITTED) {
                    if ($this->release_submitted_view($maharasubmission->viewid, array(), $maharasubmission->iscollection) === false) {
                        throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
                    }
                }

                // Update submission data.
                $maharasubmission->viewid = $data->viewid;
                $maharasubmission->viewurl = $response['url'];
                $maharasubmission->viewtitle = clean_text($response['title']);
                $maharasubmission->viewstatus = $status;
                $maharasubmission->iscollection = (int) $iscollection;
                $params['objectid'] = $maharasubmission->id;
                $updatestatus = $DB->update_record('assignsubmission_mahara', $maharasubmission);
                $event = \assignsubmission_mahara\event\submission_updated::create($params);
                $event->set_assign($this->assignment);
                $event->trigger();
                return $updatestatus;
            } else {
                if ($data->viewid === null) {
                    return $DB->delete_records('assignsubmission_mahara', array('submission' => $submission->id));
                } else {
                    // We are dealing with the new submission.
                    $maharasubmission = new stdClass();
                    $maharasubmission->viewid = $data->viewid;
                    $maharasubmission->viewurl = $response['url'];
                    $maharasubmission->viewtitle = clean_text($response['title']);
                    $maharasubmission->viewstatus = $status;
                    $maharasubmission->iscollection = (int) $iscollection;

		            $maharasubmission->submission = $submission->id;
		            $maharasubmission->assignment = $this->assignment->get_instance()->id;
		            $maharasubmission->id = $DB->insert_record('assignsubmission_mahara', $maharasubmission);
		            $params['objectid'] = $maharasubmission->id;
		            $event = \assignsubmission_mahara\event\submission_created::create($params);
		            $event->set_assign($this->assignment);
		            $event->trigger();
		            return $maharasubmission->id > 0;
                }
            }
        }
    }

    /**
     * Check if the submission plugin has all the required data to allow the work
     * to be submitted for grading
     * @param stdClass $submission the assign_submission record being submitted.
     * @return bool|string 'true' if OK to proceed with submission, otherwise a
     *                        a message to display to the user
     */
    public function precheck_submission($submission) {
        $maharasubmission = $this->get_mahara_submission($submission->id);
        if (!$maharasubmission) {
            return get_string('emptysubmission', 'assignsubmission_mahara');
        }
        return true;
    }

     /**
      * Process submission for grading
      *
      * @global stdClass $DB
      * @param stdClass $submission
      * @return void
      */
    public function submit_for_grading($submission) {
        global $DB;

        // If the submission has been locked in the gradebook, then it has already been submitted on the Mahara side
        $flags = $this->assignment->get_user_flags($submission->userid, false);
        if ($flags && $flags->locked == 1) {
            return;
        }

        $maharasubmission = $this->get_mahara_submission($submission->id);
        // Lock view on Mahara side as it has been submitted for assessment.
        if (!$response = $this->submit_view($submission, $maharasubmission->viewid, $maharasubmission->iscollection)) {
            throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
        }
        $maharasubmission->viewurl = $response['url'];
        $maharasubmission->viewstatus = self::STATUS_SUBMITTED;

        if (!$this->get_config('lock')) {
            if ($this->release_submitted_view($maharasubmission->viewid, array(), $maharasubmission->iscollection) === false) {
                throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
            }
            $maharasubmission->viewstatus = self::STATUS_RELEASED;
        }

        $DB->update_record('assignsubmission_mahara', $maharasubmission);
    }

    /**
      * Process locking
      *
      * @global stdClass $DB
      * @param stdClass $submission
      * @return void
      */
    public function lock($submission, stdClass $flags = null) {
        global $DB;

        $maharasubmission = $this->get_mahara_submission($submission->id);

        // If we're not using page locking, then don't need to do anything special here.
        // If no page is selected, then we don't need to do anything special here.
        // If it's in submitted status, then it has already been locked and we don't need to do anything
        if (
                !$this->get_config('lock')
                || !$maharasubmission
                || !$maharasubmission->viewid
                || $maharasubmission->viewstatus == self::STATUS_SUBMITTED
        ) {
            return;
        }

        // Lock view on Mahara side
        if (!$response = $this->submit_view($submission, $maharasubmission->viewid, $maharasubmission->iscollection, $submission->userid)) {
            throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
        }
        $maharasubmission->viewurl = $response['url'];
        $maharasubmission->viewstatus = self::STATUS_SUBMITTED;
        $DB->update_record('assignsubmission_mahara', $maharasubmission);
    }

    /**
      * Process unlocking
      *
      * @global stdClass $DB
      * @param stdClass $submission
      * @return void
      */
    public function unlock($submission, stdClass $flags = null) {
        global $DB;

        // If it has been submitted, and we're using page locking, it needs to remain locked
        if ($submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED && $this->get_config('lock')) {
            return;
        }

        $maharasubmission = $this->get_mahara_submission($submission->id);

        // If no page is selected, then we don't need to do anything special here.
        // If the page isn't locked, we don't need to do anything special here.
        if (!$maharasubmission || !$maharasubmission->viewid || $maharasubmission->viewstatus !== self::STATUS_SUBMITTED) {
            return;
        }

        // Unlock view on Mahara side as it has been unlocked.
        if ($this->release_submitted_view($maharasubmission->viewid, array(), $maharasubmission->iscollection) === false) {
            throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
        }
        $this->set_mahara_submission_status($maharasubmission->submission, self::STATUS_RELEASED);
    }

    /**
      * Process reverting to draft
      *
      * @global stdClass $DB
      * @param stdClass $submission
      * @return void
      */
    public function revert_to_draft(stdClass $submission) {
        global $DB;

        // If the submission has been locked in the gradebook, then we don't want to release it on the Mahara side
        // ... unless we've disabled page locking, in which case we might as well unlock it
        $flags = $this->assignment->get_user_flags($submission->userid, false);
        if ($this->get_config('lock') && $flags && $flags->locked == 1) {
            return;
        }

        $maharasubmission = $this->get_mahara_submission($submission->id);
        if ($maharasubmission->viewstatus === self::STATUS_SUBMITTED) {
            // Unlock view on Mahara side as it has been reverted to draft.
            if ($this->release_submitted_view($maharasubmission->viewid, array(), $maharasubmission->iscollection) === false) {
                throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
            }
            $this->set_mahara_submission_status($submission->id, self::STATUS_RELEASED);
        }
    }

    /**
     * Check if submission has been made
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        $maharasubmission = $this->get_mahara_submission($submission->id);
        return empty($maharasubmission);
    }


    /**
     * Gets the preview (popup) and link out for the portfolio
     *
     * @param string $name
     * @param string|moodle_url $url
     * @param string $title (Optional)
     * @return string
     */
    public function get_preview_url($name, $url, $title = null) {
        global $OUTPUT, $PAGE;

        $cm = $PAGE->cm;

        $icon = $OUTPUT->pix_icon('t/preview', $name);
        $params = array('title' => $title ?: $name);

        $url = new moodle_url('/mod/assign/submission/mahara/launch.php', array('url' => $url, 'id' => $cm->id));

        $popup_icon = html_writer::link($url->out(false), $icon, $params + array(
          'class' => 'portfolio popup',
        ));

        $link = html_writer::link($url, $name, $params);

        return "$popup_icon $link";
    }

    /**
     * Display the view of submission.
     *
     * @global stdClass $DB
     * @global stdClass $USER
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        global $PAGE, $OUTPUT, $DB, $USER;

        $PAGE->requires->js('/mod/assign/submission/mahara/js/popup.js');

        $result = '';
        $maharasubmission = $this->get_mahara_submission($submission->id);
        if ($maharasubmission) {
            $fields = array( 'assignment' => $submission->assignment );
            if (!empty($submission->groupid)) {
                $fields['groupid'] = $submission->groupid;
            }
            if (!empty($submission->userid)) {
                $fields['userid'] = $submission->userid;
            }
            $lastattempt = $DB->get_field('assign_submission', 'max(attemptnumber)', $fields);
            if ($submission->attemptnumber < $lastattempt) {
                $result .= get_string('previousattemptsnotvisible', 'assignsubmission_mahara');
            } else {
                // Either the page is viewed by the author or access code has been issued
                return $this->get_preview_url($maharasubmission->viewtitle, $maharasubmission->viewurl);
            }
        }
        return $result;
    }

    /**
     * @see parent
     *
     * @param stdClass $submission
     * @param bool $showviewlink (Mutable)
     * @return string
     */
    public function view_summary(stdClass $submission, &$showviewlink) {
        return $this->view($submission);
    }

     /**
     * Return true if this plugin can upgrade an old Moodle 2.2 assignment of this type and version.
     *
     * @param string $type old assignment subtype
     * @param int $version old assignment version
     * @return bool True if upgrade is possible
     */
    public function can_upgrade($type, $version) {
        if ($type == 'mahara' && $version >= 2011070110) {
            return true;
        }
        return false;
    }

    /**
     * Upgrade the settings from the old assignment to the new plugin based one
     *
     * @param context $oldcontext - the database for the old assignment context
     * @param stdClass $oldassignment - the database for the old assignment instance
     * @param string $log record log events here
     * @return bool Was it a success?
     */
    public function upgrade_settings(context $oldcontext, stdClass $oldassignment, & $log) {
        // $this->set_config('mnethostid', $oldassignment->var2);
        return true;
    }

    /**
     * Upgrade the submission from the old assignment to the new one
     *
     * @param context $oldcontext - the database for the old assignment context
     * @param stdClass $oldassignment The data record for the old assignment
     * @param stdClass $oldsubmission The data record for the old submission
     * @param stdClass $submission The data record for the new submission
     * @param string $log Record upgrade messages in the log
     * @return bool true or false - false will trigger a rollback
     */
    public function upgrade(context $oldcontext, stdClass $oldassignment, stdClass $oldsubmission, stdClass $submission, & $log) {
        global $DB;

        $maharadata = unserialize($oldsubmission->data2);

        $maharasubmission = new stdClass();
        $maharasubmission->viewid = $maharadata['id'];
        $maharasubmission->viewurl = $maharadata['url'];
        $maharasubmission->viewtitle = $maharadata['title'];

        $url = new moodle_url($maharadata['url']);
        if ($url->get_param('mt')) {
            $maharasubmission->viewstatus = self::STATUS_SUBMITTED;
        }

        $maharasubmission->submission = $submission->id;
        $maharasubmission->assignment = $this->assignment->get_instance()->id;

        if (!$DB->insert_record('assignsubmission_mahara', $maharasubmission) > 0) {
            $log .= get_string('couldnotconvertsubmission', 'mod_assign', $submission->userid);
            return false;
        }
        return true;
    }

    /**
     * Formatting for log info
     *
     * @global stdClass $DB
     * @param stdClass $submission The new submission
     * @return string
     */
    public function format_for_log(stdClass $submission) {
        global $DB;
        try {

            $remotehost = (object)$this->webservice_call("mahara_user_get_extended_context", array());
        } catch (Exception $e) {
            debugging("Remote host webservice call failed: ".$e->getCode().":".$e->getMessage());
            throw new moodle_exception('errorwsrequest', 'assignsubmission_mahara', '', $e->getMessage());
        }
        $remotehost->jumpurl = $remotehost->siteurl;
        $remotehost->name = $remotehost->sitename;

        if ($maharasubmission = $this->get_mahara_submission($submission->id)) {
            $maharasubmission->remotehostname = $remotehost->name;
            $output = get_string('outputforlog', 'assignsubmission_mahara', $maharasubmission);
        } else {
            $output = get_string('outputforlognew', 'assignsubmission_mahara', $remotehost->name);
        }
        return $output;
    }

    /**
     * The assignment has been deleted - cleanup
     *
     * @global stdClass $DB
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        // First of all release all pages on remote site.
        $records = $DB->get_records(
                'assignsubmission_mahara',
                array(
                        'assignment' => $this->assignment->get_instance()->id,
                        'viewstatus' => self::STATUS_SUBMITTED
                )
        );
        foreach ($records as $record) {
            if ($this->release_submitted_view($record->viewid, array(), $record->iscollection) === false) {
                // A problem on the Mahara side should not prevent the assignment from being deleted.
                // But it's worth printing a message to the error logs.
                debugging(get_string('errorrequest', 'assignsubmission_mahara', $this->get_error()));
            }
        }
        // Now delete records.
        $DB->delete_records('assignsubmission_mahara', array('assignment'=>$this->assignment->get_instance()->id));

        return true;
    }

    /**
     * Carry out any extra processing required when a student is given a new attempt
     * (i.e. when the submission is "reopened"
     * @param stdClass $oldsubmission The previous attempt
     * @param stdClass $newsubmission The new attempt
     */
    public function add_attempt(stdClass $oldsubmission, stdClass $newsubmission) {
        global $DB;
        // Unlock the previous submission's page if the assignment is reopened. That way
        // the student can make improvements and then resubmit.
        $maharasubmission = $this->get_mahara_submission($oldsubmission->id);
        if ($maharasubmission && $maharasubmission->viewstatus == self::STATUS_SUBMITTED) {
            if ($this->release_submitted_view($maharasubmission->viewid, array(), $maharasubmission->iscollection) === false) {
                throw new moodle_exception('errorrequest', 'assignsubmission_mahara', '', $this->get_error());
            }
            $this->set_mahara_submission_status($maharasubmission->submission, self::STATUS_RELEASED);
        }
    }

    /**
     * Helper method to set the status of the an assignsubmission_mahara record
     *
     * @param int $submissionid
     * @param string $status
     * @throws moodle_exception
     * @return boolean
     */
    public function set_mahara_submission_status($submissionid, $status) {
        global $DB;
        if (!($status === self::STATUS_SELECTED || $status === self::STATUS_SUBMITTED || $status === self::STATUS_RELEASED)) {
            throw new moodle_exception('errorinvalidstatus', 'assignsubmission_mahara');
        }
        return $DB->set_field('assignsubmission_mahara', 'viewstatus', $status, array('submission' => $submissionid));
    }
}
