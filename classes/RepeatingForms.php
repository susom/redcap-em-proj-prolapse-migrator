<?php
namespace Stanford\ProjProlapseMigrator;

/** @var ProjProlapseMigrator $module */

use \REDCap;
use \Project;
//use \Records;

/*
 * For longitudinal projects, the returned data is in the form of:
    [record_id 1]
        [event_id 1]
            [instance a form]       => {form data}
            [instance b form]       => {form data}
            . . .
        [event_id 2]
            [instance a form]       => {form data}
            [instance b form]       => {form data}
            . . .
        [event_id n]
            [instance a form]       => {form data}
            [instance b form]       => {form data}
            . . .
    [record_id 2]
        [event_id 1]
            [instance a form]       => {form data}
        [event_id y]
            [instance a form]       => {form data}


 * For classical projects, the returned data is in the form of:
    [record_id 1]
        [instance 1 form]       => {form data}
        [instance 2 form]       => {form data}
        . . .


The instance identifiers (i.e. a, b) are used to depict the first and second instances.  The number of the instance
may not be uniformly increasing numerically since some instances may have been deleted. For instance, instance 2 may
have been deleted, so the instance numbers would be instance 1 and instance 3.

If using the instance filter, only instances which match the filter criteria will be returned so the instance numbers
will vary.

*/


/**
 * Class RepeatingForms
 * @package Stanford\Utilities
 *
 */
class RepeatingForms
{
    // Metadata
    protected $Proj;
    private $pid;
    private $is_longitudinal;
    private $data_dictionary;
    private $fields;
    protected $events_enabled = array();    // Array of event_ids where the instrument is enabled
    private $instrument;

    // Instance
    private $event_id;
    private $data;
    private $data_loaded = false;
    private $dirty = true;
    private $record_id;

    // Last error message
    public $last_error_message = null;

    private $data_table = 'redcap_data';
    public function __construct($pid)
    {
        global $Proj, $module;

        if ($Proj->project_id == $pid) {
            $this->Proj = $Proj;
        } else {
            $this->Proj = new Project($pid);
        }

        if (empty($this->Proj) or ($this->Proj->project_id != $pid)) {
            $this->last_error_message = "Cannot determine project ID in RepeatingForms";
        }
        $this->pid = $pid;

        $this->data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($pid) : "redcap_data";

        /**
        // Find the fields on this repeating instrument
        $this->instrument = $instrument_name;

        if ($instrument_name === null) {
            $this->data_dictionary = REDCap::getDataDictionary($pid, 'array', false, null);

        } else {
            $this->data_dictionary = REDCap::getDataDictionary($pid, 'array', false, null, array($instrument_name));
        }

        $this->fields = array_keys($this->data_dictionary);


        // Is this project longitudinal?
        $this->is_longitudinal = $this->Proj->longitudinal;

        // If this is not longitudinal, retrieve the event_id
        if (!$this->is_longitudinal) {
            $this->event_id = array_keys($this->Proj->eventInfo)[0];
        }

        // Retrieved events
        $all_events = $this->Proj->getRepeatingFormsEvents();

        // See which events have this form enabled
        foreach (array_keys($all_events) as $event) {
            $fields_in_event = REDCap::getValidFieldsByEvents($this->pid, $event, false);
            $field_intersect = array_intersect($fields_in_event, $this->fields);
            if (isset($field_intersect) && sizeof($field_intersect) > 0) {
                array_push($this->events_enabled, $event);
            }
        }
         */
    }

    public static function byForm($pid, $instrument_name) {
        $instance = new self($pid);
        // Find the fields on this repeating instrument
        $instance->instrument = $instrument_name;
        $instance->data_dictionary = REDCap::getDataDictionary($pid, 'array', false, null, array($instrument_name));
        $instance->fields = array_keys($instance->data_dictionary);

        // Is this project longitudinal?
        $instance->is_longitudinal = $instance->Proj->longitudinal;

        // If this is not longitudinal, retrieve the event_id
        if (!$instance->is_longitudinal) {
            $instance->event_id = array_keys($instance->Proj->eventInfo)[0];
        }

        // Retrieved events
        $all_events = $instance->Proj->getRepeatingFormsEvents();

        //TODO: make sure this works if form is enable in multiple events (not repeating everywhere)
        // See which events have this form enabled
        foreach (array_keys($all_events) as $event) {
            $fields_in_event = REDCap::getValidFieldsByEvents($instance->pid, $event, false);
            $field_intersect = array_intersect($fields_in_event, $instance->fields);
            if (isset($field_intersect) && sizeof($field_intersect) > 0) {
                array_push($instance->events_enabled, $event);
            }
        }

        return $instance;

    }

    public static function byEvent($pid, $event) {
        global $module;

        $instance = new self($pid);

        $all_events = $instance->Proj->getRepeatingFormsEvents();
        //$module->emDebug($all_events);


        // Is this project longitudinal?
        $instance->is_longitudinal = $instance->Proj->longitudinal;

        // If this is not longitudinal, retrieve the event_id
        if (!$instance->is_longitudinal) {
            $instance->event_id = array_keys($instance->Proj->eventInfo)[0];
        } else {
            $instance->event_id = $event;
        }

        // Retrieved events
        $all_events = $instance->Proj->getRepeatingFormsEvents();



        // Find the fields on this repeating event
        $fields_in_event = REDCap::getValidFieldsByEvents($pid, $event, false);
        //$module->emDebug($fields_in_event);

        //only get datadictionary for field in events
        $instance->data_dictionary = REDCap::getDataDictionary($pid, 'array', false, $fields_in_event);
        $instance->fields = array_keys($instance->data_dictionary);
        //$module->emDebug($instance->fields);

        //just set the the events_enabled to this event
        array_push($instance->events_enabled, $event);


        /**
        // See which events have this form enabled
        foreach (array_keys($all_events) as $event) {
            $fields_in_event = REDCap::getValidFieldsByEvents($instance->pid, $event, false);
            $field_intersect = array_intersect($fields_in_event, $instance->fields);
            if (isset($field_intersect) && sizeof($field_intersect) > 0) {
                array_push($instance->events_enabled, $event);
            }
        }
*/
        return $instance;
    }


    /**
     * This function will load data internally from the database using the record, event and optional
     * filter in the calling arguments here as well as pid and instrument name from the constructor.  The data
     * is saved internally in $this->data.  The calling program must then call one of the get* functions
     * to retrieve the data.
     *
     * @param $record_id
     * @param null $event_id
     * @param null $filter
     * @return None
     */
    public function loadData($record_id, $event_id=null, $filter=null)
    {
        global $module;

        $this->record_id = $record_id;
        if (!is_null($event_id)) {
            $this->event_id = $event_id;
        }

        // Filter logic will only return matching instances
        $return_format = 'array';
        $repeating_forms = REDCap::getData($this->pid, $return_format, array($record_id), $this->fields, $this->event_id, NULL, false, false, false, $filter, true);

        // If this is a classical project, we are not adding event_id.
        foreach (array_keys($repeating_forms) as $record) {
            foreach ($this->events_enabled as $event) {
                if (!is_null($repeating_forms[$record]["repeat_instances"][$event]) and !empty($repeating_forms[$record_id]["repeat_instances"][$event])) {
                    if ($this->is_longitudinal) {
                        $this->data[$record_id][$event] = $repeating_forms[$record_id]["repeat_instances"][$event][$this->instrument];
                    } else {
                        $this->data[$record_id] = $repeating_forms[$record_id]["repeat_instances"][$event][$this->instrument];
                    }
                }
            }
        }

        $this->data_loaded = true;
        $this->dirty = false;

    }



    /**
     *
     * @param $record_id
     * @param null $event_id
     * @param null $filter
     * @return None
     */
    public function checkRecordExistsWithFilter($record_id, $event_id=null, $filter=null)
    {
        global $module;
        $found = false;
        $module->emDebug($record_id, $event_id, $filter);

        $this->record_id = $record_id;
        if (!is_null($event_id)) {
            $this->event_id = $event_id;
        }

        // Filter logic will only return matching instances
        $return_format = 'array';
        $repeating_forms = REDCap::getData($this->pid, $return_format, array($record_id), $this->fields, $this->event_id, NULL, false, false, false, $filter, true);

        $module->emDebug($repeating_forms);

        //if empty then return false

        // else already exists, return true;
        return false;
    }

    /**
     * This function will return the data retrieved based on a previous loadData call. All instances of an
     * instrument fitting the criteria specified in loadData will be returned. See the file header for the
     * returned data format.
     *
     * @param $record_id
     * @param null $event_id
     * @return array (of data loaded from loadData) or false if an error occurred
     */
    public function getAllInstances($record_id, $event_id=null) {

        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "You must supply an event_id for longitudinal projects in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // Check to see if we have the correct data loaded. If not, load it.
        if ($this->data_loaded == false || $this->record_id != $record_id || $this->event_id != $event_id) {
            $this->loadData($record_id, $event_id, null);
        }

        return $this->data;
    }

    /**
     * This function will return one instance of data retrieved in dataLoad using the $instance_id.
     *
     * @param $record_id
     * @param $instance_id
     * @param null $event_id
     * @return array (of instance data) or false if an error occurs
     */
    public function getInstanceById($record_id, $instance_id, $event_id=null)
    {
        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "You must supply an event_id for longitudinal projects in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // Check to see if we have the correct data loaded.
        if ($this->data_loaded == false || $this->record_id != $record_id || $this->event_id != $event_id) {
            $this->loadData($record_id, $event_id, null);
        }

        // If the record and optionally event match, return the data.
        if ($this->is_longitudinal) {
            if (!empty($this->data[$record_id][$event_id][$instance_id]) &&
                !is_null($this->data[$record_id][$event_id][$instance_id])) {
                return $this->data[$record_id][$event_id][$instance_id];
            } else {
                $this->last_error_message = "Instance number is invalid";
                return false;
            }
        } else {
            if (!empty($this->data[$record_id][$instance_id]) && !is_null($this->data[$record_id][$instance_id])) {
                return $this->data[$record_id][$instance_id];
            } else {
                $this->last_error_message = "Instance number is invalid";
                return false;
            }
        }
    }

    /**
     * This function will return the first instance_id for this record and optionally event. This function
     * does not return data. If the instance data is desired, call getInstanceById using the returned instance id.
     *
     * @param $record_id
     * @param null $event_id
     * @return int (instance number) or false (if an error occurs)
     */
     public function getFirstInstanceId($record_id, $event_id=null) {
        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "You must supply an event_id for longitudinal projects in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // Check to see if we have the correct data loaded.
        if ($this->data_loaded == false || $this->record_id != $record_id || $this->event_id != $event_id) {
            $this->loadData($record_id, $event_id, null);
        }

        // If the record and optionally event match, return the data.
        if ($this->is_longitudinal) {
            if (!empty(array_keys($this->data[$record_id][$event_id])[0]) &&
                !is_null(array_keys($this->data[$record_id][$event_id])[0])) {
                return array_keys($this->data[$record_id][$event_id])[0];
            } else {
                $this->last_error_message = "There are no instances in event $this->event_id for record $record_id " . __FUNCTION__;
                return false;
            }
        } else {
            if (!empty(array_keys($this->data[$record_id])[0]) && !is_null(array_keys($this->data[$record_id])[0])) {
                return array_keys($this->data[$record_id])[0];
            } else {
                $this->last_error_message = "There are no instances for record $record_id " . __FUNCTION__;
                return false;
            }
        }
    }

    /**
     * This function will return the last instance_id for this record and optionally event. This function
     * does not return data. To retrieve data, call getInstanceById using the returned $instance_id.
     *
     * @param $record_id
     * @param null $event_id
     * @return int | false (If an error occurs)
     */
    public function getLastInstanceId($record_id, $event_id=null) {

        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "You must supply an event_id for longitudinal projects in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // Check to see if we have the correct data loaded.
        //todo as leeann about forcing reload
        if ($this->data_loaded == false || $this->record_id != $record_id || $this->event_id != $event_id || $this->dirty == true) {
            //doesn't this need to be reloaded to get the latest
            $this->loadData($record_id, $event_id, null);
        }

        // If the record_ids (and optionally event_ids) match, return the data.
        if ($this->is_longitudinal) {
            $size = sizeof($this->data[$record_id][$event_id]);
            if ($size < 1) {
                //todo ask lee ann: not an error? this will prompt return of 1 as first instance
                //$this->last_error_message = "There are no instances in event $event_id for record $record_id " . __FUNCTION__;
                //return false;  //shouldn't this return null so that it starts with 1? (line 423);
                return null;
            } else {
                return array_keys($this->data[$record_id][$event_id])[$size - 1];
            }
        } else {
            $size = sizeof($this->data[$record_id]);
            if ($size < 1) {
                $this->last_error_message = "There are no instances for record $record_id " . __FUNCTION__;
                return false;
            } else {
                return array_keys($this->data[$record_id])[$size - 1];
            }
        }
    }

    /**
     *
     * @param $record
     * @param $event
     * @return int|mixed
     */
    public function getNextInstanceIDForceReload($record, $event) {
        global $module;

        //getData for all surveys for this record

        //$get_data = array('redcap_repeat_instance');
        $params = array(
            'return_format'       => 'json',
            //'fields'              => $get_data, //we need to leave this open in order to get the instance id
            'records'             => $record,
            'events'              => $event
        );
        $q = REDCap::getData($params);
        $results = json_decode($q, true);

        $max_id = max(array_column($results, 'redcap_repeat_instance'));

        return $max_id + 1;
    }

    /**
     * Rewrite of getting next instance id in repeating event (rather than getData)
     *
     * @param $record
     * @param $event
     * @return int|mixed|string
     */
    public function getNextInstanceIDSQL($record, $event) {
        global $module;

        $sql = sprintf("
            select
               rd.record, max(instance) as 'max_instance'
            from
                %s rd
            where
                rd.record = '%s'
            and rd.event_id = %d
            and rd.project_id = %d",
            db_escape($this->data_table),
            db_escape($record),
            db_escape($event),
        $module->getProjectId()
        );
        //$module->emDebug("SQL: ". $sql);
        $q = db_query($sql);

        if ($row=db_fetch_assoc($q)) {
            $instance = empty( $row['max_instance'] ) ? 0 : $row['max_instance'];

            //max instance will be returned empty if n= 1 OR n=0
            //so check the existence of $row['record'] to determine if 0 or 1
            if (($instance == 0) && $row['record'] == $record) {
                $instance = 1;
            }
        } else {
            $instance = 0;
        }
        $result = $instance +1;

        return $result;
    }


    /**
     * FIXME: this sometimes give stale ID. temp fix add another method which force loads data again
     * This function will return the next instance_id in the sequence that does not currently exist.
     * If there are no current instances, it will return 1.
     *
     * @param $record_id
     * @param null $event_id
     * @return int | false (if an error occurs)
     */
    public function getNextInstanceId($record_id, $event_id=null) {
        global $module;

        // If this is a longitudinal project, the event_id must be supplied.
        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "You must supply an event_id for longitudinal projects in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // Find the last instance and add 1 to it. If there are no current instances, return 1.
        $last_index = $this->getLastInstanceId($record_id, $event_id);

        if (is_null($last_index)) {
            return 1;
        } else {
            return ++$last_index;
        }
    }

    /**
     * This function will save an instance of data.  If the instance_id is supplied, it will overwrite
     * the current data for that instance with the supplied data. An instance_id must be supplied since
     * instance 1 is actually stored as null in the database.  If an instance is not supplied, an error
     * will be returned.
     *
     * @param $record_id
     * @param $data
     * @param null $instance_id
     * @param null $event_id
     * @return true | false (if an error occurs)
     */
    public function saveInstance($record_id, $data, $instance_id = null, $event_id = null)
    {
        global $module;

        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "Event ID Required for longitudinal project in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            //todo: ask why not use event_id passed in...
            //$event_id = $this->event_id;
        }

        // If the instance ID is null, get the next one because we are saving a new instance
        if (is_null($instance_id)) {
            $this->last_error_message = "Instance ID is required to save data " . __FUNCTION__;
            return false;
        } else {
            $next_instance_id = $instance_id;
        }

        $module->emDebug("Saving repeating form rec # $record_id in event $event_id with instance $next_instance_id");
        // Include instance and format into REDCap expected format

        //if the field is in a repeating FORM (not event), then the instrument must be specified.
        $instrument = NULL;
        if (($event_id == $module->getProjectSetting("main-config-event-id")) && (!empty($instance_id)) ) {
            foreach ($data as $key => $val) {
                $instrument = $this->data_dictionary[$key]['form_name'];
                $new_instance[$record_id]['repeat_instances'][$event_id][$instrument][$next_instance_id][$key] =$val;
            }

        } else {


            //as of 9.9.1. this array saveData doesn't seem to save the instances number
            $new_instance[$record_id]['repeat_instances'][$event_id][$instrument][$next_instance_id] = $data;
        }
        $return = REDCap::saveData($this->pid, 'array', $new_instance);
        $this->dirty = true; //set object as dirty to prompt reload later.

        /**
        //try the json saveData
        $proj = new \Project($this->pid);
        $event_name = $proj->getUniqueEventNames($event_id);

        $params = array(
            REDCap::getRecordIdField() => $record_id,
            'redcap_event_name' => $event_name,
            'redcap_repeat_instance' => $next_instance_id,
        );

        $merged = array_merge($params, $data);

        $return = REDCap::saveData($this->pid, 'json', json_encode(array($merged)));
        //$this->emDebug($sub, data, $response,  "Save Response for count"); exit;
*/



//        $module->emDebug($return["errors"], $return['item_count']);

        if (!empty($return["errors"]) and ($return["item_count"] <= 0)) {
            $module->emError("Problem saving instance $next_instance_id for record $record_id in event $event_id in project $this->pid.", $return["errors"]);
            $module->emError(json_encode($new_instance));
            $this->last_error_message = "Problem saving instance $next_instance_id for record $record_id in event $event_id in project $this->pid. Returned: " . json_encode($return);
            return false;
        } else {
            return true;
        }
    }

    // TBD: Not sure how to delete an instance ????
    public function deleteInstance($record_id, $instance_id, $event_id = null) {

        global $module;
        $module->emLog("This is the pid in deleteInstance $this->pid");
        // If longitudinal and event_id = null, send back an error
        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "Event ID Required for longitudinal project in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // *** Copy deleteRecord from Records.php  *****
        // Collect all queries in array for logging
        $sql_all = array();

        $event_sql = "AND event_id IN ($event_id)";
        $event_sql_d = "AND d.event_id IN ($event_id)";

        // "Delete" edocs for 'file' field type data (keep its row in table so actual files can be deleted later from web server, if needed).
        // NOTE: If *somehow* another record has the same doc_id attached to it (not sure how this would happen), then do NOT
        // set the file to be deleted (hence the left join of d2).
        $sql_all[] = $sql = "update redcap_metadata m, redcap_edocs_metadata e, $this->data_table d left join $this->data_table d2
							on d2.project_id = d.project_id and d2.value = d.value and d2.field_name = d.field_name and d2.record != d.record
							set e.delete_date = '".NOW."' where m.project_id = " . $this->pid . " and m.project_id = d.project_id
							and e.project_id = m.project_id and m.element_type = 'file' and d.field_name = m.field_name
							and d.value = e.doc_id and e.delete_date is null and d.record = '" . $record_id . "'
							and d.instance = '" . $instance_id . "'
							and d2.project_id is null $event_sql_d";
        db_query($sql);
        // "Delete" edoc attachments for Data Resolution Workflow (keep its record in table so actual files can be deleted later from web server, if needed)
        $sql_all[] = $sql = "update redcap_data_quality_status s, redcap_data_quality_resolutions r, redcap_edocs_metadata m
							set m.delete_date = '".NOW."' where s.project_id = " . $this->pid . " and s.project_id = m.project_id
							and s.record = '" . $record_id . "' $event_sql and s.status_id = r.status_id
							and s.instance = " . $instance_id . "
							and r.upload_doc_id = m.doc_id and m.delete_date is null";
        db_query($sql);
        // Delete record from data table
        $sql_all[] = $sql = "DELETE FROM $this->data_table WHERE project_id = " . $this->pid . " AND record = '" . $record_id . "' AND instance = " . $instance_id . " $event_sql";
        db_query($sql);
        $module->emLog("Deleted from redcap_data: " . $sql);

        // Also delete from locking_data and esignatures tables
        $sql_all[] = $sql = "DELETE FROM redcap_locking_data WHERE project_id = " . $this->pid . " AND record = '" . $record_id . "' AND instance = " . $instance_id . " $event_sql";
        db_query($sql);
        $sql_all[] = $sql = "DELETE FROM redcap_esignatures WHERE project_id = " . $this->pid . " AND record = '" . $record_id . "' AND instance = " . $instance_id . " $event_sql";
        db_query($sql);
        // Delete from calendar - no instance in table
        //$sql_all[] = $sql = "DELETE FROM redcap_events_calendar WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "' $event_sql";
        //db_query($sql);
        // Delete records in survey invitation queue table
        // Get all ssq_id's to delete (based upon both email_id and ssq_id)
        $subsql =  "select q.ssq_id from redcap_surveys_scheduler_queue q, redcap_surveys_emails e,
					redcap_surveys_emails_recipients r, redcap_surveys_participants p
					where q.record = '" .$record_id . "' and q.email_recip_id = r.email_recip_id and e.email_id = r.email_id
					and q.instance = '" . $instance_id . "'
					and r.participant_id = p.participant_id and p.event_id = $event_id";
        // Delete all ssq_id's
        $subsql2 = pre_query($subsql);
        if ($subsql2 != "''") {
            $sql_all[] = $sql = "delete from redcap_surveys_scheduler_queue where ssq_id in ($subsql2)";
            db_query($sql);
        }
        // Delete responses from survey response table for this arm
        /*
        $sql = "select r.response_id, p.participant_id, p.participant_email
				from redcap_surveys s, redcap_surveys_response r, redcap_surveys_participants p
				where s.project_id = " . $this->pid . " and r.record = '" . $record_id . "'
				and s.survey_id = p.survey_id and p.participant_id = r.participant_id and p.event_id in $event_id";
        $q = db_query($sql);
        if (db_num_rows($q) > 0)
        {
            // Get all responses to add them to array
            $response_ids = array();
            while ($row = db_fetch_assoc($q))
            {
                // If email is blank string (rather than null or an email address), then it's a record's follow-up survey "participant",
                // so we can remove it from the participants table, which will also cascade to delete entries in response table.
                if ($row['participant_email'] === '') {
                    // Delete from participants table (which will cascade delete responses in response table)
                    $sql_all[] = $sql = "DELETE FROM redcap_surveys_participants WHERE participant_id = ".$row['participant_id'];
                    db_query($sql);
                } else {
                    // Add to response_id array
                    $response_ids[] = $row['response_id'];
                }
            }
            // Remove responses (I don't think instance is the same as $instance_id??????
            if (!empty($response_ids)) {
                $sql_all[] = $sql = "delete from redcap_surveys_response where response_id in (".implode(",", $response_ids).") and instance = " . $instance_id;
                db_query($sql);
            }
        }
        */
        /*
        // Delete record from randomization allocation table (if have randomization module enabled)
        if ($randomization && Randomization::setupStatus())
        {
            // If we have multiple arms, then only undo allocation if record is being deleted from the same arm
            // that contains the randomization field.
            $removeRandomizationAllocation = true;
            if ($multiple_arms) {
                $Proj = new Project(PROJECT_ID);
                $randAttr = Randomization::getRandomizationAttributes();
                $randomizationEventId = $randAttr['targetEvent'];
                // Is randomization field on the same arm as the arm we're deleting the record from?
                $removeRandomizationAllocation = ($Proj->eventInfo[$randomizationEventId]['arm_id'] == $arm_id);
            }
            // Remove randomization allocation
            if ($removeRandomizationAllocation)
            {
                $sql_all[] = $sql = "update redcap_randomization r, redcap_randomization_allocation a set a.is_used_by = null
									 where r.project_id = " . PROJECT_ID . " and r.rid = a.rid and a.project_status = $status
									 and a.is_used_by = '" . db_escape($fetched) . "'";
                db_query($sql);
            }
        }
        */
        // Delete record from Data Quality status table
        $sql_all[] = $sql = "DELETE FROM redcap_data_quality_status WHERE project_id = " . PROJECT_ID . " AND record = '" . $record_id . "' $event_sql AND instance = $instance_id";
        db_query($sql);
        // Delete all records in redcap_ddp_records
        //$sql_all[] = $sql = "DELETE FROM redcap_ddp_records WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "'";
        //db_query($sql);
        // Delete all records in redcap_surveys_queue_hashes
        //$sql_all[] = $sql = "DELETE FROM redcap_surveys_queue_hashes WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "'";
        //db_query($sql);
        // Delete all records in redcap_new_record_cache
        //$sql_all[] = $sql = "DELETE FROM redcap_new_record_cache WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "'";
        //db_query($sql);
        // If we're required to provide a reason for changing data, then log it here before the record is deleted.
        //$change_reason = ($require_change_reason && isset($_POST['change-reason'])) ? $_POST['change-reason'] : "";
        //Logging
        //Logging::logEvent(implode(";\n", $sql_all),"redcap_data","delete",$fetched,"$table_pk = '$fetched'","Delete record$appendLoggingDescription",$change_reason);
        // **** End copy/paste *****

        return true;
    }

    /**
     * Return the data dictionary for this form
     *
     * @return array
     */
    public function getDataDictionary()
    {
        return $this->data_dictionary;
    }

    /**
     * This function will look for the data supplied in the given record/event and send back the instance
     * number if found.  The data supplied does not need to be all the data in the instance, just the data that
     * you want to search on.
     *
     * @param $needle
     * @param $record_id
     * @param null $event_id
     * @return int | false (if an error occurs)
     */
    public function exists($needle, $record_id, $event_id=null) {

        // Longitudinal projects need to supply an event_id
        if ($this->is_longitudinal && is_null($event_id)) {
            $this->last_error_message = "Event ID Required for longitudinal project in " . __FUNCTION__;
            return false;
        } else if (!$this->is_longitudinal) {
            $event_id = $this->event_id;
        }

        // Check to see if we have the correct data loaded.
        if ($this->data_loaded == false || $this->record_id != $record_id || $this->event_id != $event_id) {
            $this->loadData($record_id, $event_id, null);
        }

        // Look for the supplied data in an already created instance
        $found_instance_id = null;
        $size_of_needle = sizeof($needle);
        if ($this->is_longitudinal) {
            foreach ($this->data[$record_id][$event_id] as $instance_id => $instance) {
                $intersected_fields = array_intersect_assoc($instance, $needle);
                if (sizeof($intersected_fields) == $size_of_needle) {
                    $found_instance_id = $instance_id;
                }
            }
        } else {
            foreach ($this->data[$this->record_id] as $instance_id => $instance) {
                $intersected_fields = array_intersect_assoc($instance, $needle);
                if (sizeof($intersected_fields) == $size_of_needle) {
                    $found_instance_id = $instance_id;
                }
            }
        }

        // Supplied data did not match any instance data
        if (is_null($found_instance_id)) {
            $this->last_error_message = "Instance was not found with the supplied data " . __FUNCTION__;
        }

        return $found_instance_id;
    }

    public function getInstrument() {
        return $this->instrument;
    }


}
