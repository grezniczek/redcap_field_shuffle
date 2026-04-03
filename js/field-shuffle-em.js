/// <reference path="./field-shuffle-em.typedef.js" />
// Field Shuffle EM
// @ts-check
; (function () {

	//#region Variables and Initialization

	/** @type {string} */
	const APP_NAME = 'Field Shuffle';

	/** @type {FieldShuffleWindow} */
	const redcapWindow = window;

	if (typeof redcapWindow.REDCap == 'undefined') {
		redcapWindow.REDCap = {
			EM: {}
		};
	}
	if (typeof redcapWindow.REDCap.EM == 'undefined') {
		redcapWindow.REDCap.EM = {
			RUB: {}
		};
	}
	if (typeof redcapWindow.REDCap.EM.RUB == 'undefined') {
		redcapWindow.REDCap.EM.RUB = {};
	}
	redcapWindow.REDCap.EM.RUB.FieldShuffle = {
		init: init,
	};

	/** @type {FieldShuffleConfig | null} */
	let config = null;

	/**
	 * Stores the module configuration and schedules field shuffling once the page is ready.
	 * @param {FieldShuffleConfig} data Configuration emitted by the PHP module.
	 * @return {void}
	 */
	function init(data) {
		config = data;
		log(config);
		$(shuffleFields);
	}

	/**
	 * Returns the active module configuration.
	 * @return {FieldShuffleConfig} Current runtime configuration.
	 */
	function getConfig() {
		if (config === null) {
			throw new Error('Field Shuffle config has not been initialized.');
		}
		return config;
	}

	//#endregion

	//#region Field Shuffling

	/**
	 * Converts grouped field names into the serialized field-order string stored in the target field.
	 * @param {Array<Array<string>>} arr Field groups to serialize.
	 * @return {string} Serialized field order.
	 */
	function concat_fields(arr) {
		/** @type {Array<string>} */
		const groups = [];
		for (const i of arr) {
			groups.push(i.join('+'));
		}
		return groups.join('-');
	}

	/**
	 * Splits a serialized field-order string into its individual field names.
	 * @param {string} s Serialized field-order string.
	 * @return {Array<string>} Individual field names.
	 */
	function dissect_fields(s) {
		return s.split(new RegExp('[+-]'));
	}

	/**
	 * Applies the configured field shuffle to each target field on the current page.
	 * @return {void}
	 */
	function shuffleFields() {
		const activeConfig = getConfig();
		for (const target in activeConfig.targets) {
			const this_target = activeConfig.targets[target];
			const map = this_target.map;
			try {
				let shuffled = concat_fields(this_target.shuffled);
				let original = concat_fields(this_target.original);
				log('Shuffling "' + target + '": ' + original + ' -> ' + shuffled);
				const $target = $('input[type=text][name="' + $.escapeSelector(target) + '"]');
				if ($target.length != 1) {
					warn('Target field "' + target + '" not found.');
					continue;
				}
				if ($target.val() != '') {
					shuffled = '' + $target.val();
					const shuffledItems = dissect_fields(shuffled);
					log('Target field "' + target + '" already has a value: ' + shuffled, shuffledItems);
					if (shuffledItems.length == this_target.length) {
						// Apply stored order to map
						for (let i = 0; i < shuffledItems.length; i++) {
							map[this_target.original_flat[i]] = shuffledItems[i];
						}
						log('Updated map:', map);
					}
					else {
						warn('Stored order is not compatible - aborting.');
						continue;
					}
				}
				else {
					$target.val(shuffled);
				}
				/** @type {Record<string, FieldShuffleRowState>} */
				const orig = {};
				for (const fieldName in this_target.map) {
					log('Preparing field "' + fieldName + '"');
					const $row = $('tr[sq_id="' + $.escapeSelector(fieldName) + '"]');
					const $num = $row.find('td.questionnum');
					// Add hidden marker row before and save questionnum
					const $mark = $('<tr></tr>');
					$mark.attr('data-shuffle-mark', fieldName);
					$mark.css('display', 'none');
					if (activeConfig.isSurvey) {
						$num.before($num.clone(false));
						$mark.append($num);
					}
					$row.before($mark);
					/** @type {JQuery<HTMLElement>} */
					const typedRow = /** @type {JQuery<HTMLElement>} */ ($row);
					/** @type {JQuery<HTMLElement>} */
					const typedNum = /** @type {JQuery<HTMLElement>} */ ($num);
					/** @type {JQuery<HTMLElement>} */
					const typedMark = /** @type {JQuery<HTMLElement>} */ ($mark);
					orig[fieldName] = {
						row: typedRow,
						num: typedNum,
						mark: typedMark
					};
				}
				log('Preparation complete:', orig);
				for (const fieldName in this_target.map) {
					const toField = map[fieldName];
					log('Moving field "' + toField + '" -> ' + fieldName);
					orig[toField].row.insertAfter(orig[fieldName].mark);
					if (activeConfig.isSurvey) {
						const $num = orig[toField].row.find('td.questionnum');
						$num.before(orig[fieldName].num);
						$num.remove();
					}
				}
				// Remove marker rows
				$('[data-shuffle-mark]').remove();
			}
			catch (err) {
				error(err);
			}
		}
	}

	//#endregion

	//#region Debug Logging
	/**
	 * Logs a message to the console when in debug mode.
	 * @return {void}
	 */
	function log() {
		const activeConfig = getConfig();
		if (!activeConfig.debug) return;
		let ln = '??';
		try {
			const line = ('' + (new Error).stack).split('\n')[2];
			const parts = line.split(':');
			ln = parts[parts.length - 2];
		}
		catch { }
		log_print(ln, 'log', arguments);
	}
	/**
	 * Logs a warning to the console when in debug mode.
	 * @return {void}
	 */
	function warn() {
		const activeConfig = getConfig();
		if (!activeConfig.debug) return;
		let ln = '??';
		try {
			const line = ('' + (new Error).stack).split('\n')[2];
			const parts = line.split(':');
			ln = parts[parts.length - 2];
		}
		catch { }
		log_print(ln, 'warn', arguments);
	}
	/**
	 * Logs an error to the console.
	 * @return {void}
	 */
	function error() {
		let ln = '??';
		try {
			const line = ('' + (new Error).stack).split('\n')[2];
			const parts = line.split(':');
			ln = parts[parts.length - 2];
		}
		catch { }
		log_print(ln, 'error', arguments);
	}
	/**
	 * Prints a message to the console with a standard module prefix.
	 * @param {string} ln Line number where log was called from.
	 * @param {'log'|'warn'|'error'} mode Console method to call.
	 * @param {IArguments} args Arguments passed to the logging helper.
	 * @return {void}
	 */
	function log_print(ln, mode, args) {
		const prompt = APP_NAME + ' [' + ln + ']';
		switch (args.length) {
			case 1:
				console[mode](prompt, args[0]);
				break;
			case 2:
				console[mode](prompt, args[0], args[1]);
				break;
			case 3:
				console[mode](prompt, args[0], args[1], args[2]);
				break;
			case 4:
				console[mode](prompt, args[0], args[1], args[2], args[3]);
				break;
			case 5:
				console[mode](prompt, args[0], args[1], args[2], args[3], args[4]);
				break;
			case 6:
				console[mode](prompt, args[0], args[1], args[2], args[3], args[4], args[5]);
				break;
			default:
				console[mode](prompt, args);
				break;
		}
	}
	//#endregion

})();
