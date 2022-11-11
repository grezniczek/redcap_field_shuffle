<?php namespace RUB\REDCapScrambleExternalModule;

require_once "classes/InjectionHelper.php";

/**
 * ExternalModule class for REDCap Scramble.
 */
class REDCapScrambleExternalModule extends \ExternalModules\AbstractExternalModule {

    #region Constructor and Instance Variables

    /**
     * @var InjectionHelper
     */
    public $ih = null;
 
    function __construct() {
        parent::__construct();
        $this->ih = InjectionHelper::init($this);
    }

    #endregion

    #region Hooks

    function redcap_data_entry_form($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {

        $this->ih->js("js/redcap_scramble.js", true);

    }

    function redcap_survey_page($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {

        $this->ih->js("js/redcap_scramble.js", true);
    }

    #endregion

}