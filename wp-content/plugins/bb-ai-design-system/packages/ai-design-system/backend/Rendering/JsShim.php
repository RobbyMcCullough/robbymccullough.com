<?php

namespace FL\DesignSystem\Rendering;

/**
 * JavaScript shims for design system script execution.
 *
 * When base JS is injected after DOMContentLoaded has already fired
 * (e.g., BB AJAX layout renders, Block Editor dynamic insertion),
 * any DOMContentLoaded/load listeners silently fail. These shims
 * intercept those listeners and fire them immediately when the
 * document is already past that state.
 */
class JsShim {

	/**
	 * Wrap base JS with a DOMContentLoaded/load shim.
	 *
	 * Unlike wrap_js_iife() (used for section JS), this does NOT wrap
	 * the code in an IIFE — base JS must stay in global scope so
	 * section JS can call its utility functions.
	 *
	 * The shim temporarily replaces document.addEventListener and
	 * window.addEventListener before the base JS executes, then
	 * restores the originals after.
	 *
	 * @param  string $js Raw base JavaScript code.
	 * @return string Shimmed JavaScript code.
	 */
	public static function wrap_base_js( string $js ): string {
		// Store originals on a temporary global so they survive across
		// the separate script scopes (setup IIFE → inline base JS → teardown).
		return 'window.__dsAEL={d:document.addEventListener.bind(document),w:window.addEventListener.bind(window)};'
			. '(function(o){'
			. 'function s(orig,el){return function(t,fn,a){'
			. 'if(t==="DOMContentLoaded"&&document.readyState!=="loading"){fn();return}'
			. 'if(t==="load"&&document.readyState==="complete"){fn();return}'
			. 'return orig.call(el,t,fn,a);'
			. '};}'
			. 'document.addEventListener=s(o.d,document);'
			. 'window.addEventListener=s(o.w,window);'
			. '})(window.__dsAEL);' . "\n"
			. $js . "\n"
			. 'document.addEventListener=window.__dsAEL.d;'
			. 'window.addEventListener=window.__dsAEL.w;'
			. 'delete window.__dsAEL;';
	}
}
