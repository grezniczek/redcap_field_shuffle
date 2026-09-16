# Field Shuffle

[![DOI](https://zenodo.org/badge/DOI/10.5281/zenodo.20782543.svg)](https://doi.org/10.5281/zenodo.20782543)

Field Shuffle is a REDCap External Module that puts fields into a random order.

## Installation

- Install this module from the REDCap External Module Repository and enable it.

Manual installation:

- Clone this repo into `<redcap-root>/modules/redcap_field_shuffle_v<version-number>`.
- Go to _Control Center > External Modules > Manage_ and enable 'Field Shuffle'.

## Configuration

A **debug** mode can be enabled in the module's project settings. When enabled, some information about the module's actions that may be useful for troubleshooting is output to the browser console.

## Usage

The module provides five action tags:

| Action tag | Surface | Purpose |
| --- | --- | --- |
| `@SHUFFLE-FIELDS-SURVEY` | Survey | Shuffle fields or fixed-order blocks within one already-rendered survey page. |
| `@SHUFFLE-FIELDS-DATAENTRY` | Data entry | Shuffle fields or fixed-order blocks on a data entry form, typically to reproduce a participant's stored survey order. |
| `@SHUFFLE-FIELDS-PAGED` | Survey | Shuffle individual fields among selected positions while preserving the existing page structure. |
| `@SHUFFLE-FIELDS-PAGED-SINGLE` | Survey | Shuffle fields or field groups as units and make every realized unit one survey page. |
| `@SHUFFLE-FIELDS-SH` | Survey | Optionally override the page header for a `PAGED-SINGLE` unit. This companion tag does not generate or store an order. |

### Order-storage field

The first four tags produce a realized order. Apply the selected shuffle tag to the field that should store that order. The storage field must be an unvalidated _Text Box_ field and must not be included in its own shuffle expression. Each shuffle tag requires a quoted expression identifying the controlled fields; the supported grouping syntax depends on the selected mode.

It is recommended to add REDCap's `@HIDDEN-SURVEY` and `@READONLY` action tags to the storage field. `@HIDDEN-SURVEY` is particularly important for `@SHUFFLE-FIELDS-PAGED-SINGLE`: the module omits a hidden storage field from the request-local survey layout so it cannot create an otherwise blank page.

### Within-page shuffling

`@SHUFFLE-FIELDS-SURVEY` randomizes fields on a survey page. The storage field and every shuffled field must be on that same page.

`@SHUFFLE-FIELDS-DATAENTRY` provides equivalent behavior on data entry forms. To reproduce the order seen by a survey participant, place both within-page tags on the same storage field with exactly the same expression.

For example, to randomize four survey questions, _q1_ through _q4_, add the following to another field, such as _displayed_order_:

```text
@SHUFFLE-FIELDS-SURVEY="q1,q2,q3,q4"
@HIDDEN-SURVEY @READONLY
```

### Block shuffling

The two within-page tags support fixed-order blocks. Group fields with parentheses to shuffle them as one unit. The fields inside each block remain in the specified order.

For example, if questions _b1_ through _b3_ and _b6_ through _b7_ should stay together, add the following to the order-storage field:

```text
@SHUFFLE-FIELDS-SURVEY="(b1,b2,b3),b4,b5,(b6,b7)"
@HIDDEN-SURVEY @READONLY
```

Shuffle results might then be _b5-b1+b2+b3-b6+b7-b4_ or _b6+b7-b1+b2+b3-b5-b4_. The stored order uses `+` as the in-block delimiter instead of `-`. Parentheses must be matched and cannot be nested. Separate field names and blocks with commas, as shown above.

When the survey or data entry form loads, the realized field order is written to the storage field. On surveys, question-number cells remain in their display positions, so automatic numbering stays sequential after the shuffle.

When a survey page is rendered that already has (valid) data in the field holding the field order, then this order will be replicated. If the stored data is invalid, then no field reordering will occur.

### Multi-page survey shuffling

The module also provides two survey-only modes that reorder effective instrument metadata before REDCap builds its survey pages. Enable REDCap's survey setting to display each section on a separate page when using these modes. REDCap remains responsible for navigation, validation, branching logic, calculations, piping, Save & Return Later, and survey completion.

#### Fixed page structure: `@SHUFFLE-FIELDS-PAGED`

Use `@SHUFFLE-FIELDS-PAGED` to shuffle complete field definitions among selected field slots while keeping section-header positions and non-selected fields fixed:

> `@SHUFFLE-FIELDS-PAGED="q1,q2,q3,q4,q5,q6"`

Every configured item must be one field. Parenthesized groups and `~` are not supported in this mode. Fields may be scattered across multiple pages.

#### One shuffle unit per page: `@SHUFFLE-FIELDS-PAGED-SINGLE`

Use `@SHUFFLE-FIELDS-PAGED-SINGLE` to make each configured field or parenthesized group one survey page:

> `@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2,q3~q4),q5,q6"`

Top-level items are shuffled. Within parentheses, comma joins fixed-order subsequences and `~` shuffles fields within one subsequence. In the example, `q2` remains first while `q3` and `q4` are shuffled. The whole parenthesized unit stays together on one page.

Fixed fields split a configuration into contiguous regions. Units are shuffled independently within each region so fixed fields remain in place and differently sized units cannot cross a fixed-field boundary. Grouped fields must be contiguous in the original instrument metadata.

Add `@HIDDEN-SURVEY` to the order-storage field. In `PAGED-SINGLE` mode the module omits such a control field from the request-local survey layout while continuing to store its value server-side; this prevents it from creating a blank page.

#### Page headers

Place `@SHUFFLE-FIELDS-SH` on any field in a `PAGED-SINGLE` unit to override that unit's page header:

> `@SHUFFLE-FIELDS-SH="Question"`

Normal REDCap piping is supported, including an MLM-translated field label:

> `@SHUFFLE-FIELDS-SH="[question_header:label]"`

If several fields in one unit carry the tag, the first in original metadata order wins and the module logs a warning. Without an override, the first natural section header in original metadata order is used. If neither exists, a blank page-breaking header is generated. The tag is ignored outside a `PAGED-SINGLE` unit. Live piping is not supported.

#### Persistence and validation

The tagged Text Box field remains the source of truth. An empty field receives one generated order; a populated valid field is replayed exactly, including orders supplied externally. Singleton units are separated with `-`, fixed group subsequences with `+`, and realized within-subsequence shuffles with `~`.

Paged configurations are validated before use. Missing or duplicate fields, malformed grammar, overlapping configurations, non-contiguous groups, cross-region stored orders, and stale stored values are rejected and logged. Fields controlled by `@SHUFFLE-FIELDS-PAGED-SINGLE` cannot also be controlled by `@SHUFFLE-FIELDS-SURVEY`. An invalid stored order is never silently replaced with a new randomization.

A demo project can be downloaded [here](https://raw.githubusercontent.com/grezniczek/redcap_field_shuffle/main/demo/FieldShuffleDemo.REDCap.xml) (file hosted on GitHub).

## Changelog

Version | Comment
------- | -------------
Unreleased | Add server-side `@SHUFFLE-FIELDS-PAGED` and `@SHUFFLE-FIELDS-PAGED-SINGLE` modes, internal `~` shuffling, and `@SHUFFLE-FIELDS-SH` page headers.
1.1.5   | Security hardening in helper class.
1.1.4   | Security hardening for potential future expansions<br>Namespace change<br>Framework v16
1.1.3   | Minor Bugfix: Prevent PHP8 error when the action tag is used without any parameters.
1.1.2   | Major Bugfix: Field grouping would not work. Bug was introduced in version 1.1.1.
1.1.1   | Bugfix: Recording of shuffled field now works when the order set in the action tag parameter does not reflect the actual order of fields on the form<br>Framework v12
1.1.0   | New feature: Support for block shuffling.<br>Add action tag descriptions.
1.0.3   | Lowered version requirements (REDCap 11.4.4, EM Framework 8).
1.0.2   | EM renamed to 'Field Shuffle'.
1.0.1   | Bugfix: Question numbers are now in correct order.
1.0.0   | Initial release.

## How to cite this work

If you use this external module for a project that generates a research output, please cite this software in addition to [citing REDCap](https://projectredcap.org/resources/citations/). You can do so using the APA referencing style as below:

> Rezniczek, G. A. (2026). Field Shuffle (REDCap External Module) [Computer software]. https://doi.org/10.5281/zenodo.20782543

Or by adding this reference to your BibTeX database:

```bibtex
@software{Rezniczek_FieldShuffle_REDCap_EM_2026,
author = {Rezniczek, Günther A.},
doi = {10.5281/zenodo.20782543},
title = {{Field Shuffle (REDCap External Module)}},
url = {https://github.com/grezniczek/redcap_field_shuffle},
version = {1.1.5},
year = {2026}
}
```

These instructions are also available in [GitHub](https://github.com/grezniczek/redcap_field_shuffle) under 'Cite This Repository'.

## Support this work

If you find this software useful, you can [buy me a coffee or a beer](https://www.paypal.com/donate/?hosted_button_id=6VRC2JFRCBGRN). Your support is purely voluntary and helps me continue improving this project. Of course, you are not entitled to any special benefits—except my silent appreciation while enjoying the drink! 🍻☕

You can use the link or the QR code below to make a donation via PayPal.

![PayPal QR Code](images/qr-paypal.png)

_Please note that donations are purely voluntary and not tax-deductible._


---

**Disclaimer**

Parts of this documentation and release polish were developed with assistance from OpenAI's ChatGPT/Codex to support clarity, consistency, and ease of use for REDCap project designers. Final content has been reviewed and adapted by the maintainer to reflect the specific functionality and standards of the *Field Shuffle* external module.
