<?php
require_once('../../config.php');
require_once($CFG->dirroot.'/local/special_consideration/classes/form/application_form.php');
require_once($CFG->dirroot.'/course/lib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

if ($courseid == 0) {
    $courseid = $fromform->courseid ?? $COURSE->id ?? 0;
}
if ($courseid == 0) {
    throw new moodle_exception('missingcourseid', 'local_special_consideration');
}

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

function get_readable_type($type) {
    return get_string('type_' . str_replace('-', '_', $type), 'local_special_consideration', $type);
}

function get_readable_status($status) {
    return get_string('status_' . $status, 'local_special_consideration', ucfirst($status));
}

// Check for view capability
if (!has_capability('local/special_consideration:view', $context)) {
    throw new required_capability_exception($context, 'local/special_consideration:view', 'nopermissions', 'local_special_consideration');
}

$PAGE->set_url(new moodle_url('/local/special_consideration/apply.php', array('courseid' => $courseid)));
$PAGE->set_title(get_string('specialconsideration', 'local_special_consideration'));
$PAGE->set_heading($course->fullname);

$PAGE->requires->css('/local/special_consideration/styles.css');

echo $OUTPUT->header();

$mform = new \local_special_consideration\form\application_form(null, array('course' => $course));

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
} else if ($fromform = $mform->get_data()) {
    // Check for apply capability before saving the application
    if (!has_capability('local/special_consideration:apply', $context)) {
        throw new required_capability_exception($context, 'local/special_consideration:apply', 'nopermissions', 'local_special_consideration');
    }

    // Save the application
    $application = new stdClass();
    $application->courseid = $courseid;
    $application->userid = $USER->id;
    $application->type = $fromform->type;
    $application->affectedassessment = $fromform->affectedassessment;
    $application->dateaffected = $fromform->dateaffected;
    $application->reason = $fromform->reason;
    $application->additionalcomments = $fromform->additionalcomments;
    $application->status = 'pending';
    $application->timecreated = time();

    $applicationid = $DB->insert_record('local_special_consideration', $application);

    if (!empty($fromform->supportingdocs)) {
        file_save_draft_area_files($fromform->supportingdocs, $context->id, 'local_special_consideration', 'supportingdocs', $applicationid);
        
        $application->id = $applicationid;
        $application->supportingdocs = $fromform->supportingdocs;
        $DB->update_record('local_special_consideration', $application);
    }

    redirect(new moodle_url('/local/special_consideration/apply.php', array('courseid' => $courseid)),
            get_string('applicationsubmitted', 'local_special_consideration'),
            null, \core\output\notification::NOTIFY_SUCCESS);
}

// Check user roles
$canManage = has_capability('local/special_consideration:manage', $context);
$isStudent = has_capability('local/special_consideration:apply', $context) && !$canManage;

if ($canManage) {
    // Admin/Teacher view
    if ($action === 'new') {
        echo html_writer::tag('h3', get_string('newapplication', 'local_special_consideration'));
        $mform->display();
    } else {
        // Pending applications
        echo html_writer::tag('h3', get_string('pendingapplications', 'local_special_consideration'));
        
        $pendingapplications = $DB->get_records_sql(
            "SELECT sc.*, u.firstname, u.lastname
            FROM {local_special_consideration} sc
            JOIN {user} u ON sc.userid = u.id
            WHERE sc.courseid = :courseid AND sc.status = 'pending'
            ORDER BY sc.timecreated DESC",
           array('courseid' => $courseid)
);

        if (empty($pendingapplications)) {
            echo html_writer::tag('p', get_string('nopendingapplications', 'local_special_consideration'));
        } else {
            $modinfo = get_fast_modinfo($course); //get course module information

            $table = new html_table();
            $table->head = array(
                get_string('datesubmitted', 'local_special_consideration'),
                get_string('type', 'local_special_consideration'),
                get_string('affectedassessment', 'local_special_consideration'),
                get_string('status', 'local_special_consideration'),
                get_string('studentname', 'local_special_consideration'),
                get_string('actions', 'local_special_consideration')
            );
            
            foreach ($pendingapplications as $application) {
                $viewurl = new moodle_url('/local/special_consideration/view.php', array('id' => $application->id, 'courseid' => $courseid));
                $actions = html_writer::link($viewurl, get_string('view', 'local_special_consideration'));
                $displayType = get_readable_type($application->type);
                $affectedAssessment = get_string('notspecified', 'local_special_consideration');
                if (!empty($application->affectedassessment) && isset($modinfo->cms[$application->affectedassessment])) {
                    $cm = $modinfo->cms[$application->affectedassessment];
                    $affectedAssessment = $cm->name;
                }
                
                $row = array(
                    userdate($application->timecreated),
                    $displayType,
                    $affectedAssessment,
                    get_readable_status($application->status), 
                    fullname($application),
                    $actions
                );

                $table->data[] = $row;
            }

            echo html_writer::table($table);
        }

        echo html_writer::empty_tag('hr', array('class' => 'divider'));

        // Previous applications
        echo html_writer::tag('h3', get_string('previousapplications', 'local_special_consideration'));
        
        $previousapplications = $DB->get_records_sql(
            "SELECT sc.*, u.firstname, u.lastname, t.firstname AS teacherfirstname, t.lastname AS teacherlastname
             FROM {local_special_consideration} sc
             JOIN {user} u ON sc.userid = u.id
             LEFT JOIN {user} t ON sc.reviewerid = t.id
             WHERE sc.courseid = :courseid AND sc.status != 'pending'
             ORDER BY sc.timecreated DESC",
            array('courseid' => $courseid)
        );

        if (empty($previousapplications)) {
            echo html_writer::tag('p', get_string('nopreviousapplications', 'local_special_consideration'));
        } else {
            $table = new html_table();
            $table->head = array(
                get_string('datesubmitted', 'local_special_consideration'),
                get_string('type', 'local_special_consideration'),
                get_string('status', 'local_special_consideration'),
                get_string('studentname', 'local_special_consideration'),
                get_string('reviewedby', 'local_special_consideration'),
                get_string('actions', 'local_special_consideration')
            );

            foreach ($previousapplications as $application) {
                $viewurl = new moodle_url('/local/special_consideration/view.php', array('id' => $application->id, 'courseid' => $courseid));
                $actions = html_writer::link($viewurl, get_string('view', 'local_special_consideration'));

                $reviewedby = $application->teacherfirstname && $application->teacherlastname 
                    ? fullname((object)['firstname' => $application->teacherfirstname, 'lastname' => $application->teacherlastname])
                    : get_string('notapplicable', 'local_special_consideration');

                    $displayType = get_string('type_' . $application->type, 'local_special_consideration', $application->type);
                
                    $row = array(
                    userdate($application->timecreated),
                    $displayType, 
                    get_readable_status($application->status), 
                    fullname($application),
                    $reviewedby,
                    $actions
                );

                $table->data[] = $row;
            }

            echo html_writer::table($table);
        }
    }
} else {
    // Student view
    if ($action === 'new') {
        // Check for apply capability before displaying the form
        if (!has_capability('local/special_consideration:apply', $context)) {
            throw new required_capability_exception($context, 'local/special_consideration:apply', 'nopermissions', 'local_special_consideration');
        }
        echo html_writer::tag('h3', get_string('newapplication', 'local_special_consideration'));
        $mform->display();
    } else {
        // Display "Create New Application" button
        $create_new_url = new moodle_url('/local/special_consideration/apply.php', array('courseid' => $courseid, 'action' => 'new'));
        $create_new_button = $OUTPUT->single_button($create_new_url, get_string('createnewapplication', 'local_special_consideration'), 'get');
        echo html_writer::div($create_new_button, 'create-new-application');

        echo html_writer::empty_tag('hr', array('class' => 'divider'));

        // Display previous applications
        echo html_writer::tag('h3', get_string('previousapplications', 'local_special_consideration'));

        $applications = $DB->get_records('local_special_consideration', array('userid' => $USER->id, 'courseid' => $courseid), 'timecreated DESC');

        if (empty($applications)) {
            echo html_writer::tag('p', get_string('nopreviousapplications', 'local_special_consideration'));
        } else {
            $table = new html_table();
            $table->head = array(
                get_string('datesubmitted', 'local_special_consideration'),
                get_string('type', 'local_special_consideration'),
                get_string('status', 'local_special_consideration'),
                get_string('actions', 'local_special_consideration')
            );

            foreach ($applications as $application) {
                $viewurl = new moodle_url('/local/special_consideration/view.php', array('id' => $application->id, 'courseid' => $courseid));
                $editurl = new moodle_url('/local/special_consideration/edit.php', array('id' => $application->id, 'courseid' => $courseid));
                
                $actions = html_writer::link($viewurl, get_string('view', 'local_special_consideration'));
             
                if ($application->status === 'pending' || $application->status === 'more_info') {
                    $actions .= ' | ' . html_writer::link($editurl, get_string('edit', 'local_special_consideration'));
                }
                if ($application->status === 'pending') {
                    $actions .= ' | ' . html_writer::link('#', get_string('withdraw', 'local_special_consideration'), 
                        array('class' => 'withdraw-button', 'data-id' => $application->id));
                }

                $displayType = get_readable_type($application->type); 

                $row = array(
                    userdate($application->timecreated),
                    $displayType,
                    get_readable_status($application->status), 
                    $actions
                );

                $table->data[] = $row;
            }

            echo html_writer::table($table);
        }

        // JavaScript for withdraw button
        $PAGE->requires->js_amd_inline("
        require(['jquery'], function($) {
            $('.withdraw-button').on('click', function(e) {
                e.preventDefault();
                var applicationId = $(this).data('id');
                
                if (window.confirm('Are you sure you want to withdraw this application?')) {
                    $.post('" . $CFG->wwwroot . "/local/special_consideration/withdraw.php', {
                        ajax: 1,
                        id: applicationId,
                        sesskey: '" . sesskey() . "'
                    }, function(data) {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.error);
                        }
                    }, 'json');
                }
            });
        });
        ");
    }
}

echo $OUTPUT->footer();