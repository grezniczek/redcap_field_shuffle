// @ts-check

/**
 * @typedef {Record<string, string>} FieldShuffleMap
 */

/**
 * @typedef {Object} FieldShuffleTarget
 * @property {Array<Array<string>>} original Original grouped field order from the action tag.
 * @property {Array<Array<string>>} shuffled Randomized grouped field order used on the page.
 * @property {FieldShuffleMap} map Mapping from displayed field name to source field name.
 * @property {Array<string>} original_flat Flattened field order from the action tag.
 * @property {Array<string>} actual_flat Flattened field order in actual form order.
 * @property {number} length Number of fields participating in the shuffle.
 */

/**
 * @typedef {Object} FieldShuffleConfig
 * @property {boolean} debug Whether debug logging is enabled.
 * @property {boolean} isSurvey Whether the current page is a survey.
 * @property {Record<string, FieldShuffleTarget>} targets Targets keyed by shuffle storage field name.
 */

/**
 * @typedef {Object} FieldShuffleNamespace
 * @property {(data: FieldShuffleConfig) => void} init Initializes the module on page load.
 */

/**
 * @typedef {Object} FieldShuffleRowState
 * @property {JQuery<HTMLElement>} row Original field row.
 * @property {JQuery<HTMLElement>} num Question number cell.
 * @property {JQuery<HTMLElement>} mark Hidden marker row used for repositioning.
 */

/**
 * @typedef {Object} FieldShuffleRubNamespace
 * @property {FieldShuffleNamespace=} FieldShuffle Field Shuffle module namespace.
 */

/**
 * @typedef {Object} FieldShuffleEmNamespace
 * @property {FieldShuffleRubNamespace=} RUB RUB module namespace container.
 */

/**
 * @typedef {Object} FieldShuffleRedcapNamespace
 * @property {FieldShuffleEmNamespace=} EM External module namespace container.
 */

/**
 * @typedef {Window & { REDCap?: FieldShuffleRedcapNamespace }} FieldShuffleWindow
 */
