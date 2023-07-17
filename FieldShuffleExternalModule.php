<?php namespace RUB\FieldShuffleExternalModule;

require_once "classes/InjectionHelper.php";
require_once "classes/ActionTagParser.php";

/**
 * ExternalModule class for Field Shuffle.
 */
class FieldShuffleExternalModule extends \ExternalModules\AbstractExternalModule {


    const AT_SHUFFLE_SURVEY = "@SHUFFLE-FIELDS-SURVEY";
    const AT_SHUFFLE_DATAENTRY = "@SHUFFLE-FIELDS-DATAENTRY";

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
        $settings = $this->get_settings($project_id, $instrument, self::AT_SHUFFLE_DATAENTRY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = false;
            $this->ih->js("js/field-shuffle-em.js", true);
            print "<script>REDCap.EM.RUB.FieldShuffle.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
        }
    }

    function redcap_survey_page($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
        $settings = $this->get_settings($project_id, $instrument, self::AT_SHUFFLE_SURVEY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = true;
            $this->ih->js("js/field-shuffle-em.js", true);
            print "<script>REDCap.EM.RUB.FieldShuffle.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
        }
    }

    #endregion


    private function get_settings($pid, $form, $at_name) {
        $targets = [];
        $Proj = new \Project($pid);
        foreach ($Proj->forms[$form]["fields"] as $target => $_) {
            $meta = $Proj->metadata[$target];
            $misc = $meta["misc"] ?? "";
            if (strpos($misc, $at_name) !== false) {
                $result = ActionTagParser::parse($misc);
                foreach ($result["parts"] as $at) {
                    if ($at["text"] == $at_name && $at["param"]["type"] == "quoted-string") {
                        $targets[$target]["original"] = $this->parse_params($at["param"]["text"]);
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
            $targets[$target]["shuffled"] = $sorted;
            $original_flat = array_merge(...$targets[$target]["original"]);
            $shuffled_flat = array_merge(...$targets[$target]["shuffled"]);
            for ($i = 0; $i < count($original_flat); $i++) {
                $targets[$target]["map"][$original_flat[$i]] = $shuffled_flat[$i];
            }
            $targets[$target]["original_flat"] = $original_flat;
            $targets[$target]["length"] = count($original_flat);
        }
        return array(
            "debug" => $this->getProjectSetting("debug") == true,
            "targets" => $targets,
        );
    }


    private function parse_params($params) {
        $order = [];
        $pattern = '/(?|([a-z][a-z0-9_]*)|\(([^()]+)\))/';
        preg_match_all($pattern, $params, $matches);
        for ($i = 0; $i < count($matches[0]); $i++) {
            if (!empty($matches[1][$i])) {
                $order[] = array_map(function($s) { return trim($s); }, explode(",",trim($matches[1][$i],"\"")));
            } 
        }
        return $order;
    }
}