<?php namespace RUB\REDCapScrambleExternalModule;

require_once "classes/InjectionHelper.php";
require_once "classes/ActionTagParser.php";

/**
 * ExternalModule class for REDCap Scramble.
 */
class REDCapScrambleExternalModule extends \ExternalModules\AbstractExternalModule {


    const AT_SCRAMBLE_SURVEY = "@SCRAMBLE-SURVEY";
    const AT_SCRAMBLE_DATAENTRY = "@SCRAMBLE-DATAENTRY";

    #region Constructor and Instance Variables

    /**
     * @var InjectionHelper
     */
    public $ih = null;

    /**
     * EM Framework (tooling support)
     * @var \ExternalModules\Framework
     */
    private $fw;
 
    function __construct() {
        parent::__construct();
        $this->fw = $this->framework;
        $this->ih = InjectionHelper::init($this);
    }

    #endregion

    #region Hooks

    function redcap_data_entry_form($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        $settings = $this->get_scramble_settings($project_id, $instrument, self::AT_SCRAMBLE_DATAENTRY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = false;
            $this->ih->js("js/redcap_scramble.js", true);
            print "<script>REDCap.EM.RUB.REDCapScramble.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
        }
    }

    function redcap_survey_page($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
        $settings = $this->get_scramble_settings($project_id, $instrument, self::AT_SCRAMBLE_SURVEY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = true;
            $this->ih->js("js/redcap_scramble.js", true);
            print "<script>REDCap.EM.RUB.REDCapScramble.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
        }
    }

    #endregion


    private function get_scramble_settings($pid, $form, $at_name) {
        $targets = [];
        $Proj = new \Project($pid);
        foreach ($Proj->forms[$form]["fields"] as $target => $_) {
            $meta = $Proj->metadata[$target];
            $misc = $meta["misc"] ?? "";
            if (strpos($misc, $at_name) !== false) {
                $result = ActionTagParser::parse($misc);
                foreach ($result["parts"] as $at) {
                    if ($at["text"] == $at_name && $at["param"]["type"] == "quoted-string") {
                        $targets[$target]["original"] = array_map(function($s) { return trim($s); }, explode(",",trim($at["param"]["text"],"\"")));
                    }
                }
            }
        }
        foreach ($targets as $target => $target_data) {
            // Generate random order
            $sort_by = [];
            while (count($sort_by) < count($target_data["original"])) {
                $sort_by[] = random_int(PHP_INT_MIN, PHP_INT_MAX);
            }
            $sorted = array_merge($target_data["original"]);
            array_multisort($sort_by, SORT_NUMERIC, $sorted);
            $targets[$target]["scrambled"] = $sorted;
            for ($i = 0; $i < count($sorted); $i++) {
                $targets[$target]["map"][$target_data["original"][$i]] = $sorted[$i];
            }
        }
        return array(
            "debug" => $this->getProjectSetting("debug") == true,
            "targets" => $targets,
        );
    }
}