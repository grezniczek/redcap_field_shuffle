<?php

namespace DE\RUB\FieldShuffleExternalModule;

require_once "classes/InjectionHelper.php";
require_once "classes/ActionTagParser.php";
require_once "classes/PagedShuffle.php";

/**
 * ExternalModule class for Field Shuffle.
 */
class FieldShuffleExternalModule extends \ExternalModules\AbstractExternalModule
{

    const AT_SHUFFLE_SURVEY = "@SHUFFLE-FIELDS-SURVEY";
    const AT_SHUFFLE_DATAENTRY = "@SHUFFLE-FIELDS-DATAENTRY";
    const AT_SHUFFLE_PAGED = "@SHUFFLE-FIELDS-PAGED";
    const AT_SHUFFLE_PAGED_SINGLE = "@SHUFFLE-FIELDS-PAGED-SINGLE";
    const PAGED_SESSION_KEY = "rub-field-shuffle-paged";

    #region Hooks

    /**
     * Resolves and applies server-side paged shuffles before REDCap derives the
     * survey page map. The Project request cache makes the transformed order
     * visible to Project instances constructed later during form rendering.
     *
     * @param int|string|null $project_id REDCap project ID.
     * @return void
     */
    function redcap_every_page_before_render($project_id)
    {
        if (!defined("PAGE") || PAGE !== "surveys/index.php" || !is_numeric($project_id)) {
            return;
        }

        $hash = \Survey::checkSurveyHash(false);
        if (!$hash) {
            return;
        }
        $context = \Survey::getSurveyContextFromSurveyHash($hash);
        if (!is_array($context) || (string) ($context["project_id"] ?? "") !== (string) $project_id) {
            return;
        }

        global $Proj;
        if (!($Proj instanceof \Project)) {
            return;
        }
        $form = $context["form_name"] ?? "";
        if (!isset($Proj->forms[$form]["fields"])) {
            return;
        }

        $form_fields = $Proj->forms[$form]["fields"];
        $metadata = $Proj->metadata;
        $configurations = $this->getPagedConfigurations($form, $form_fields, $metadata);
        if (count($configurations) === 0) {
            return;
        }

        $invalid = $this->findConflictingConfigurations($configurations, $form_fields, $metadata);
        $this->warnOrphanSectionHeaderTags($configurations, $form, $form_fields, $metadata);
        $response = $this->getSurveyResponse($hash, $context);
        $event_id = (int) $context["event_id"];
        $instance = $response === null
            ? (isset($_GET["instance"]) && is_numeric($_GET["instance"]) ? (int) $_GET["instance"] : 1)
            : (int) $response["instance"];
        $is_initial_public_request = $response === null
            && strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET"
            && (!isset($_GET["__page__"]) || (int) $_GET["__page__"] <= 1);
        $omitted_targets = array();

        foreach ($configurations as $index => $configuration) {
            if (isset($invalid[$index])) {
                $this->logPagedWarning($invalid[$index], $form, $configuration["target"]);
                continue;
            }

            foreach ($configuration["warnings"] as $warning) {
                $this->logPagedWarning($warning, $form, $configuration["target"]);
            }

            $target = $configuration["target"];
            $stored = null;
            $order_source = "stored";
            if ($response !== null) {
                $stored = $this->readStoredOrder(
                    $project_id,
                    $response["record"],
                    $event_id,
                    $form,
                    $instance,
                    $target
                );
            }
            if (($stored === null || $stored === "")
                && $response === null
                && !$is_initial_public_request) {
                $stored = $this->getPendingOrder($project_id, $form, $event_id, $instance, $target);
                $order_source = "session";
            }

            try {
                if ($stored !== null && $stored !== "") {
                    $realized = PagedShuffle::restoreOrder($configuration, $stored);
                } else {
                    $realized = PagedShuffle::generateOrder($configuration);
                    $stored = PagedShuffle::serializeOrder($realized);
                    $order_source = "generated";
                    if ($response === null) {
                        $this->setPendingOrder($project_id, $form, $event_id, $instance, $target, $stored);
                    } else {
                        if (!$this->saveStoredOrder(
                            $project_id,
                            $response["record"],
                            $event_id,
                            $form,
                            $instance,
                            $target,
                            $stored
                        )) {
                            continue;
                        }
                    }
                }

                // Delay removing hidden storage fields until all independent
                // configurations have transformed against the same slot layout.
                $transform_configuration = $configuration;
                if (!empty($configuration["omit_target"])) {
                    $omitted_targets[$target] = true;
                    $transform_configuration["omit_target"] = false;
                }
                $transformed = PagedShuffle::transform(
                    $transform_configuration,
                    $realized,
                    $form_fields,
                    $metadata
                );
                $form_fields = $transformed["form_fields"];
                $metadata = $transformed["metadata"];
                if ($this->getProjectSetting("debug") == true) {
                    $this->framework->log("Paged field shuffle applied", array(
                        "instrument" => $form,
                        "storage_field" => $target,
                        "order" => $stored,
                        "source" => $order_source,
                    ));
                }
            } catch (\Throwable $exception) {
                $this->logPagedWarning(
                    "Stored order rejected: " . $exception->getMessage(),
                    $form,
                    $target
                );
            }
        }

        if (count($omitted_targets)) {
            $ordered_fields = array_keys($form_fields);
            foreach (array_keys($omitted_targets) as $target) {
                $position = array_search($target, $ordered_fields, true);
                if ($position === false) {
                    continue;
                }
                $header = $metadata[$target]["element_preceding_header"] ?? null;
                if ($header !== null && $header !== "") {
                    for ($i = $position + 1; $i < count($ordered_fields); $i++) {
                        $next = $ordered_fields[$i];
                        if (isset($omitted_targets[$next])) {
                            continue;
                        }
                        if (empty($metadata[$next]["element_preceding_header"])) {
                            $metadata[$next]["element_preceding_header"] = $header;
                        }
                        break;
                    }
                }
                unset($form_fields[$target]);
            }
        }

        $Proj->forms[$form]["fields"] = $form_fields;
        $Proj->metadata = $metadata;
    }

    /**
     * Persists a session-backed public-survey order after REDCap creates the
     * record on the first submitted page. The session copy remains available
     * for REDCap's immediate GET redirect, which has no response identifier.
     *
     * @return void
     */
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
        $survey_hash, $response_id, $repeat_instance)
    {
        if (!$survey_hash || !$record || !$instrument || !is_numeric($event_id)) {
            return;
        }
        $instance = is_numeric($repeat_instance) ? (int) $repeat_instance : 1;
        $pending = $this->getPendingOrders($project_id, $instrument, $event_id, $instance, $record);
        if (count($pending) === 0) {
            return;
        }

        foreach ($pending as $target => $order) {
            $existing = $this->readStoredOrder(
                $project_id,
                $record,
                $event_id,
                $instrument,
                $instance,
                $target
            );
            if ($existing === null || $existing === "") {
                if (!$this->saveStoredOrder(
                    $project_id,
                    $record,
                    $event_id,
                    $instrument,
                    $instance,
                    $target,
                    $order
                )) {
                    continue;
                }
            }
            $this->bindPendingOrder($project_id, $instrument, $event_id, $instance, $target, $record);
        }
    }

    /**
     * Injects field shuffle settings and JavaScript on data entry forms when the
     * data entry action tag is present on the instrument.
     *
     * @param int|string $project_id REDCap project ID.
     * @param string $record Record ID.
     * @param string $instrument Instrument name.
     * @param int|string $event_id Event ID.
     * @param int|string|null $group_id DAG ID, if applicable.
     * @param int|string|null $repeat_instance Repeat instance number, if applicable.
     *
     * @return void
     */
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $settings = $this->get_settings($project_id, $instrument, self::AT_SHUFFLE_DATAENTRY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = false;
            $this->init_js($settings, true);
        }
    }

    /**
     * Injects field shuffle settings and JavaScript on survey pages when the
     * survey action tag is present on the instrument.
     *
     * @param int|string $project_id REDCap project ID.
     * @param string $record Record ID.
     * @param string $instrument Instrument name.
     * @param int|string $event_id Event ID.
     * @param int|string|null $group_id DAG ID, if applicable.
     * @param string $survey_hash Survey hash.
     * @param int|string|null $response_id Survey response ID.
     * @param int|string|null $repeat_instance Repeat instance number, if applicable.
     *
     * @return void
     */
    function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $settings = $this->get_settings($project_id, $instrument, self::AT_SHUFFLE_SURVEY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = true;
            $this->init_js($settings, true);
        }
    }

    #endregion

    /**
     * Discovers and validates all paged action tags on one instrument.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getPagedConfigurations($form, $form_fields, $metadata)
    {
        $configurations = array();
        foreach (array_keys($form_fields) as $target) {
            $misc = $metadata[$target]["misc"] ?? "";
            if (!is_string($misc)
                || (strpos($misc, self::AT_SHUFFLE_PAGED) === false
                    && strpos($misc, self::AT_SHUFFLE_PAGED_SINGLE) === false)) {
                continue;
            }

            foreach (ActionTagParser::parse($misc)["parts"] as $part) {
                if (($part["type"] ?? null) !== "tag") {
                    continue;
                }
                $tag = $part["text"] ?? "";
                if ($tag !== self::AT_SHUFFLE_PAGED && $tag !== self::AT_SHUFFLE_PAGED_SINGLE) {
                    continue;
                }
                if (!is_array($part["param"] ?? null)
                    || ($part["param"]["type"] ?? null) !== "quoted-string") {
                    $this->logPagedWarning("{$tag} requires a quoted parameter.", $form, $target);
                    continue;
                }
                $mode = $tag === self::AT_SHUFFLE_PAGED
                    ? PagedShuffle::MODE_PAGED
                    : PagedShuffle::MODE_SINGLE;
                try {
                    $configurations[] = PagedShuffle::buildConfiguration(
                        $mode,
                        $target,
                        $this->unquoteActionTagParameter($part["param"]["text"] ?? ""),
                        $form_fields,
                        $metadata
                    );
                } catch (\Throwable $exception) {
                    $this->logPagedWarning($exception->getMessage(), $form, $target);
                }
            }
        }
        return $configurations;
    }

    /** @return array<int, string> Configuration indexes mapped to errors. */
    private function findConflictingConfigurations($configurations, $form_fields, $metadata)
    {
        $owners = array();
        $targets = array();
        foreach ($configurations as $index => $configuration) {
            $targets[$configuration["target"]][] = $index;
            foreach ($configuration["fields"] as $field) {
                $owners[$field][] = $index;
            }
        }

        $invalid = array();
        foreach ($owners as $field => $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $invalid[$index] = "Field '{$field}' is owned by more than one paged shuffle configuration.";
                }
            }
            if (isset($targets[$field])) {
                foreach (array_unique(array_merge($indexes, $targets[$field])) as $index) {
                    $invalid[$index] = "Order-storage field '{$field}' is included in a paged shuffle configuration.";
                }
            }
        }
        foreach ($targets as $target => $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $invalid[$index] = "Order-storage field '{$target}' carries multiple paged shuffle configurations.";
                }
            }
        }

        $client_side_fields = $this->getReferencedActionTagFields(
            self::AT_SHUFFLE_SURVEY,
            $form_fields,
            $metadata
        );
        foreach ($configurations as $index => $configuration) {
            if ($configuration["mode"] !== PagedShuffle::MODE_SINGLE) {
                continue;
            }
            $overlap = array_values(array_intersect($configuration["fields"], $client_side_fields));
            if (count($overlap)) {
                $invalid[$index] = "PAGED-SINGLE fields cannot also be controlled by "
                    . self::AT_SHUFFLE_SURVEY . ": " . implode(", ", $overlap) . ".";
            }
        }
        return $invalid;
    }

    private function warnOrphanSectionHeaderTags($configurations, $form, $form_fields, $metadata)
    {
        $single_fields = array();
        foreach ($configurations as $configuration) {
            if ($configuration["mode"] === PagedShuffle::MODE_SINGLE) {
                $single_fields = array_merge($single_fields, $configuration["fields"]);
            }
        }
        $single_fields = array_unique($single_fields);

        foreach (array_keys($form_fields) as $field) {
            if (in_array($field, $single_fields, true)) {
                continue;
            }
            if ($this->containsActionTag($metadata[$field]["misc"] ?? "", PagedShuffle::AT_SECTION_HEADER)) {
                $this->logPagedWarning(
                    PagedShuffle::AT_SECTION_HEADER . " is ignored outside a PAGED-SINGLE unit.",
                    $form,
                    $field
                );
            }
        }
    }

    private function getReferencedActionTagFields($tag_name, $form_fields, $metadata)
    {
        $fields = array();
        foreach (array_keys($form_fields) as $field) {
            $misc = $metadata[$field]["misc"] ?? "";
            if (!is_string($misc) || strpos($misc, $tag_name) === false) {
                continue;
            }
            foreach (ActionTagParser::parse($misc)["parts"] as $part) {
                if (($part["type"] ?? null) !== "tag" || ($part["text"] ?? null) !== $tag_name
                    || !is_array($part["param"] ?? null)
                    || ($part["param"]["type"] ?? null) !== "quoted-string") {
                    continue;
                }
                $parameter = $this->unquoteActionTagParameter($part["param"]["text"] ?? "");
                preg_match_all('/[a-z][a-z0-9_]*/', $parameter, $matches);
                $fields = array_merge($fields, $matches[0]);
            }
        }
        return array_values(array_unique($fields));
    }

    private function containsActionTag($misc, $tag_name)
    {
        if (!is_string($misc) || strpos($misc, $tag_name) === false) {
            return false;
        }
        foreach (ActionTagParser::parse($misc)["parts"] as $part) {
            if (($part["type"] ?? null) === "tag" && ($part["text"] ?? null) === $tag_name) {
                return true;
            }
        }
        return false;
    }

    /** @return array{record: string, instance: int}|null */
    private function getSurveyResponse($hash, $context)
    {
        $participant_id = \Survey::getParticipantIdFromHash($hash);
        if (!$participant_id) {
            return null;
        }

        $response_id = isset($_POST["__response_id__"]) && is_numeric($_POST["__response_id__"])
            ? (int) $_POST["__response_id__"]
            : null;
        if ($response_id === null && !empty($_POST["__response_hash__"])) {
            $decrypted = \Survey::decryptResponseHash($_POST["__response_hash__"], $participant_id);
            if (is_numeric($decrypted)) {
                $response_id = (int) $decrypted;
            }
        }
        if ($response_id === null && !empty($_GET["__rh"])) {
            $decrypted = \Survey::decryptResponseHash($_GET["__rh"], $participant_id);
            if (is_numeric($decrypted)) {
                $response_id = (int) $decrypted;
            }
        }
        if ($response_id === null && ($context["participant_email"] ?? null) === null) {
            return null;
        }

        $sql = "SELECT r.record, COALESCE(r.instance, 1) AS instance
                FROM redcap_surveys_response r
                WHERE r.participant_id = ?";
        $parameters = array($participant_id);
        if ($response_id !== null) {
            $sql .= " AND r.response_id = ?";
            $parameters[] = $response_id;
        }
        $sql .= " ORDER BY r.response_id DESC LIMIT 1";
        $result = $this->framework->query($sql, $parameters);
        $row = $result->fetch_assoc();
        if (!is_array($row) || ($row["record"] ?? "") === "") {
            return null;
        }
        return array(
            "record" => $row["record"],
            "instance" => (int) $row["instance"],
        );
    }

    private function readStoredOrder($project_id, $record, $event_id, $form, $instance, $target)
    {
        $Proj = new \Project($project_id);
        $table = \Records::getDataTable($project_id);
        $sql = "SELECT value FROM {$table}
                WHERE project_id = ? AND event_id = ? AND record = ? AND field_name = ?";
        $parameters = array($project_id, $event_id, $record, $target);
        if ($Proj->isRepeatingEvent($event_id) || $Proj->isRepeatingForm($event_id, $form)) {
            $sql .= " AND instance = ?";
            $parameters[] = $instance;
        } else {
            $sql .= " AND instance IS NULL";
        }
        $sql .= " LIMIT 1";
        $result = $this->framework->query($sql, $parameters);
        $row = $result->fetch_assoc();
        return is_array($row) ? (string) ($row["value"] ?? "") : null;
    }

    private function saveStoredOrder($project_id, $record, $event_id, $form, $instance, $target, $order)
    {
        $Proj = new \Project($project_id);
        if ($Proj->isRepeatingEvent($event_id)) {
            $data = array(
                $record => array(
                    "repeat_instances" => array(
                        $event_id => array(
                            "" => array($instance => array($target => $order)),
                        ),
                    ),
                ),
            );
        } elseif ($Proj->isRepeatingForm($event_id, $form)) {
            $data = array(
                $record => array(
                    "repeat_instances" => array(
                        $event_id => array(
                            $form => array($instance => array($target => $order)),
                        ),
                    ),
                ),
            );
        } else {
            $data = array(
                $record => array(
                    $event_id => array($target => $order),
                ),
            );
        }

        $result = \REDCap::saveData($project_id, "array", $data, "normal");
        if (!empty($result["errors"])) {
            $this->logPagedWarning(
                "Could not persist generated order: " . implode("; ", $result["errors"]),
                $form,
                $target
            );
            return false;
        }
        return true;
    }

    private function unquoteActionTagParameter($text)
    {
        $text = (string) $text;
        if (strlen($text) < 2) {
            return $text;
        }
        $quote = $text[0];
        if (($quote !== '"' && $quote !== "'") || substr($text, -1) !== $quote) {
            return $text;
        }
        $text = substr($text, 1, -1);
        return str_replace(array("\\" . $quote, "\\\\"), array($quote, "\\"), $text);
    }

    private function getPendingOrders($project_id, $form, $event_id, $instance, $record = null)
    {
        $entries = $_SESSION[self::PAGED_SESSION_KEY][$project_id][$form][$event_id][$instance] ?? array();
        $orders = array();
        foreach ($entries as $target => $entry) {
            // Accept string entries left by an earlier module version as
            // unbound orders, then replace them on the next generation.
            if (!is_array($entry)) {
                $orders[$target] = $entry;
                continue;
            }
            $bound_record = $entry["record"] ?? null;
            if ($record !== null && $bound_record !== null && (string) $bound_record !== (string) $record) {
                continue;
            }
            $orders[$target] = $entry["order"] ?? null;
        }
        return $orders;
    }

    private function getPendingOrder($project_id, $form, $event_id, $instance, $target)
    {
        $orders = $this->getPendingOrders($project_id, $form, $event_id, $instance);
        return $orders[$target] ?? null;
    }

    private function setPendingOrder($project_id, $form, $event_id, $instance, $target, $order)
    {
        $_SESSION[self::PAGED_SESSION_KEY][$project_id][$form][$event_id][$instance][$target] = array(
            "order" => $order,
            "record" => null,
        );
    }

    private function bindPendingOrder($project_id, $form, $event_id, $instance, $target, $record)
    {
        $entry = $_SESSION[self::PAGED_SESSION_KEY][$project_id][$form][$event_id][$instance][$target] ?? null;
        if (!is_array($entry)) {
            $entry = array("order" => $entry, "record" => null);
        }
        $entry["record"] = (string) $record;
        $_SESSION[self::PAGED_SESSION_KEY][$project_id][$form][$event_id][$instance][$target] = $entry;
    }

    private function logPagedWarning($message, $form, $target)
    {
        $this->framework->log("Paged field shuffle warning: " . $message, array(
            "instrument" => $form,
            "storage_field" => $target,
        ));
    }

    /**
     * Loads the frontend script and emits the current shuffle configuration as
     * JSON for client-side initialization.
     *
     * @param array<string, mixed> $settings Shuffle settings passed to the browser.
     * @param bool $inline Whether to inject the JavaScript inline.
     *
     * @return void
     */
    private function init_js($settings, $inline)
    {
        $ih = InjectionHelper::init($this);
        $ih->js("js/field-shuffle-em.js", $inline);
        print '<script type="application/json" id="rub-fieldshuffle-settings">' .
            json_encode(
                $settings,
                JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
            ) . '</script>';
        print '<script>REDCap.EM.RUB.FieldShuffle.init(JSON.parse(document.getElementById("rub-fieldshuffle-settings").textContent));</script>';
    }

    /**
     * Builds shuffle settings for all tagged fields on a form, including the
     * randomized order and the field-to-field mapping used by the frontend.
     *
     * @param int|string $pid REDCap project ID.
     * @param string $form Instrument name.
     * @param string $at_name Action tag name to search for.
     *
     * @return array<string, mixed> Module settings for the current page.
     */
    private function get_settings($pid, $form, $at_name)
    {
        $targets = [];
        $Proj = new \Project($pid);
        foreach ($Proj->forms[$form]["fields"] as $target => $_) {
            $meta = $Proj->metadata[$target] ?? [];
            $misc = $meta["misc"] ?? "";
            if (strpos($misc, $at_name) !== false) {
                $result = ActionTagParser::parse($misc);
                foreach ($result["parts"] as $at) {
                    if (!is_array($at["param"])) continue;
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
            // To make the mapping, we need to consider that the order given in 
            // the AT parameter does not reflect the order of the fields in the form.
            // Therefore, let's get the order of the fields in the form
            $original_flat = array_merge(...$targets[$target]["original"]);
            $ordered_fields = [];
            foreach ($Proj->forms[$form]["fields"] as $field => $_) {
                $field = $this->framework->escape($field);
                if (in_array($field, $original_flat)) {
                    $ordered_fields[$Proj->metadata[$field]["field_order"]] = $field;
                }
            }
            ksort($ordered_fields);
            $ordered_fields = array_values($ordered_fields);
            $shuffled_flat = array_merge(...$targets[$target]["shuffled"]);
            // Now map based on actual order
            for ($i = 0; $i < count($ordered_fields); $i++) {
                $targets[$target]["map"][$ordered_fields[$i]] = $shuffled_flat[$i];
            }
            $targets[$target]["original_flat"] = $original_flat;
            $targets[$target]["actual_flat"] = $ordered_fields;
            $targets[$target]["length"] = count($original_flat);
        }
        return array(
            "debug" => $this->getProjectSetting("debug") == true,
            "targets" => $targets,
        );
    }


    /**
     * Parses the action tag parameter string into ordered groups of field names.
     *
     * @param string $params Action tag parameter text.
     *
     * @return array<int, array<int, string>> Parsed field group order.
     */
    private function parse_params($params)
    {
        $order = [];
        $pattern = '/(?|([a-z][a-z0-9_]*)|\(([^()]+)\))/';
        preg_match_all($pattern, $params, $matches);
        for ($i = 0; $i < count($matches[0]); $i++) {
            if (!empty($matches[1][$i])) {
                $order[] = array_map(function ($s) {
                    return trim($s);
                }, explode(",", trim($matches[1][$i], "\"")));
            }
        }
        return $order;
    }
}
