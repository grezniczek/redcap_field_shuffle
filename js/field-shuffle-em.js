// Field Shuffle EM
// @ts-check
;(function() {

//#region Variables and Initialization

const APP_NAME = 'Field Shuffle';

if (typeof window['REDCap'] == 'undefined') {
    window['REDCap'] = {
        EM: {}
    };
}
if (typeof window['REDCap']['EM'] == 'undefined') {
    window['REDCap']['EM'] = {
        RUB: {}
    };
}
if (typeof window['REDCap']['EM']['RUB'] == 'undefined') {
    window['REDCap']['EM']['RUB'] = {};
}
window['REDCap']['EM']['RUB']['FieldShuffle'] = {
    init: init,
};

let config;

function init(data) {
    config = data;
    log(config);
    $(shuffleFields);
}

//#endregion

//#region Field Shuffling

function shuffleFields() {
    for (const target in config.targets) {
        const this_target = config.targets[target];
        const map = this_target.map;
        try {
            let shuffled = this_target.shuffled.join('-');
            let original = this_target.original.join('-');
            log('Shuffling "' + target + '": ' + original + ' -> ' + shuffled);
            const $target = $('input[type=text][name="' + target + '"]');
            if ($target.length != 1) {
                warn('Target field "' + target + '" not found.');
                continue;
            }
            if ($target.val() != '') {
                shuffled = ($target.val() ?? '').toString();
                log('Target field "' + target + '" already has a value: ' + shuffled);
                const shuffledItems = shuffled.split('-');
                if (shuffledItems.length == this_target.original.length) {
                    // Apply stored order to map
                    for (let i = 0; i < shuffledItems.length; i++) {
                        map[this_target.original[i]] = shuffledItems[i];
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
            const orig = {};
            for (const fieldName of this_target.original) {
                log('Preparing field "' + fieldName +'"');
                const $row = $('tr[sq_id="' + fieldName + '"]');
                const $num = $row.find('td.questionnum');
                // Add hidden marker row before and save questionnum
                const $mark = $('<tr></tr>');
                $mark.attr('data-shuffle-mark', fieldName);
                $mark.css('display','none');
                if (config.isSurvey) {
                    $num.before($num.clone(false));
                    $mark.append($num);
                }
                $row.before($mark);
                orig[fieldName] = {
                    row: $row,
                    num: $num,
                    mark: $mark
                }
            }
            log('Preparation complete:', orig);
            for (const fieldName of this_target.original) {
                const toField = map[fieldName];
                log('Moving field "' + toField + '" -> ' + fieldName);
                orig[toField].row.insertAfter(orig[fieldName].mark);
                if (config.isSurvey) {
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
 * Logs a message to the console when in debug mode
 */
 function log() {
    if (!config.debug) return;
    let ln = '??';
    try {
        const line = ((new Error).stack ?? '').split('\n')[2];
        const parts = line.split(':');
        ln = parts[parts.length - 2];
    }
    catch { }
    log_print(ln, 'log', arguments);
}
/**
 * Logs a warning to the console when in debug mode
 */
function warn() {
    if (!config.debug) return;
    let ln = '??';
    try {
        const line = ((new Error).stack ?? '').split('\n')[2];
        const parts = line.split(':');
        ln = parts[parts.length - 2];
    }
    catch { }
    log_print(ln, 'warn', arguments);
}
/**
 * Logs an error to the console when in debug mode
 */
function error() {
    let ln = '??';
    try {
        const line = ((new Error).stack ?? '').split('\n')[2];
        const parts = line.split(':');
        ln = parts[parts.length - 2];
    }
    catch { }
    log_print(ln, 'error', arguments);
}
/**
 * Prints to the console
 * @param {string} ln Line number where log was called from
 * @param {'log'|'warn'|'error'} mode 
 * @param {IArguments} args 
 */
function log_print(ln, mode, args) {
    const prompt = APP_NAME + ' [' + ln + ']';
    switch(args.length) {
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