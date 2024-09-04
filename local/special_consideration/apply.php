<?php
require_once('../../config.php');
require_once($CFG->dirroot.'/local/special_consideration/classes/form/application_form.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid == 0) {
    $courseid = $fromform->courseid;
}
if ($courseid == 0 && isset($COURSE->id)) {
    $courseid = $COURSE->id;
}

if ($courseid == 0) {
    throw new moodle_exception('missingcourseid', 'local_special_consideration');
}

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

if (!has_capability('local/special_consideration:apply', $context)) {
    throw new required_capability_exception($context, 'local/special_consideration:apply', 'nopermissions', 'local_special_consideration');
}

$PAGE->set_url(new moodle_url('/local/special_consideration/apply.php', array('courseid' => $courseid)));
$PAGE->set_title(get_string('specialconsideration', 'local_special_consideration'));
$PAGE->set_heading($course->fullname);

$PAGE->requires->css('/local/special_consideration/styles.css');

$mform = new \local_special_consideration\form\application_form(null, array('course' => $course));

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
} else if ($fromform = $mform->get_data()) {
    // Save the application
    $courseid = $fromform->courseid;
    
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
    
        // Update the application record with the file area ID
        $application->id = $applicationid;
        $application->supportingdocs = $fromform->supportingdocs;
        $DB->update_record('local_special_consideration', $application);
        // Debugging
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_special_consideration', 'supportingdocs', $applicationid, 'id', false);
        error_log('Files saved: ' . count($files));
        foreach ($files as $file) {
            error_log('File saved: ' . $file->get_filename());
        }
    }

    redirect(new moodle_url('/local/special_consideration/apply.php', array('courseid' => $courseid)),
             get_string('applicationsubmitted', 'local_special_consideration'),
             null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'new') {
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
            if ($application->status === 'pending') {
                $actions .= ' | ' . html_writer::link($editurl, get_string('edit', 'local_special_consideration'));
                $actions .= ' | ' . html_writer::link('#', get_string('withdraw', 'local_special_consideration'), 
                    array('class' => 'withdraw-button', 'data-id' => $application->id));
            }
            
            $table->data[] = array(
                userdate($application->timecreated),
                $application->type,
                $application->status,
                $actions
            );
        }

        echo html_writer::table($table);
    }
}

$PAGE->requires->js_amd_inline("
require(['jquery'], function($) {
    $('.withdraw-button').on('click', function(e) {
        e.preventDefault();
        var applicationId = $(this).data('id');
        
        // Use a native JavaScript confirm dialog
        if (window.confirm('Are you sure you want to withdraw this application?')) {
            $.post('" . $CFG->wwwroot . "/local/special_consideration/withdraw.php', {
                ajax: 1,
                id: applicationId,
                sesskey: '" . sesskey() . "'
            }, function(data) {
                if (data.success) {
                    location.reload(); // Refresh the entire page
                } else {
                    alert(data.error);
                }
            }, 'json');
        }
    });
});
");

echo $OUTPUT->footer();