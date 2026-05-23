<?php

namespace FL\DesignSystem\Services;

/**
 * Documentation-only class describing the `bindings` slot on a DS block node.
 *
 * The `bindings` map is a sibling of `settings` on `ds_block_data`. Each entry
 * keys off a settings field name and describes how that field's value should be
 * resolved from a dynamic source at render time. The settings array remains the
 * authoritative content (a repeater's items, a flat field's literal value); the
 * binding overrides or augments that content during rendering.
 *
 * Shape:
 *
 *   bindings: {
 *     [settingsKey: string]: BindingSpec
 *   }
 *
 * BindingSpec is one of:
 *
 *   1. Data-source binding (drives a repeater from a registered source):
 *        {
 *          source:      string,    // registered source id (e.g. 'wp-post-query')
 *          config:      object,    // source-specific query config
 *          connections: object,    // per-item field key -> source property name
 *          defaults:    object,    // per-item field key -> fallback literal
 *        }
 *
 *   2. Flat-field connection (binds a single setting value to a property):
 *        {
 *          connection: string,
 *        }
 *      Future work; the resolver branches on `source` vs `connection`.
 *
 * The JS counterpart lives in
 * `frontend/src/core/data-sources/bindings-normalizer.js` and must stay in sync
 * with this contract. The same module documents the self-healing rules used
 * by the write path; on the PHP side those are summarized at the top of
 * {@see \FL\DesignSystem\Services\BindingsNormalizer}, and the live consumers
 * are `frontend/src/core/services/reconcile-bindings.js` and
 * {@see \FL\DesignSystem\DataSources\DataSourceResolver}.
 */
final class BindingsShape {

	/**
	 * Top-level field name on `ds_block_data`.
	 */
	public const FIELD = 'bindings';

	private function __construct() {}
}
