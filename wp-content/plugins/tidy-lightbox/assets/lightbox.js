( function () {
    'use strict';

    var overlay, img, caption, closeBtn, prevBtn, nextBtn, spinner;
    var groups   = {};   // { groupName: [ { href, caption } ] }
    var current  = { group: null, index: 0 };
    var touchStartX = 0;

    function init() {
        buildOverlay();
        indexLinks();
    }

    function buildOverlay() {
        overlay  = el( 'div',    { id: 'tidy-lb-overlay' } );
        spinner  = el( 'div',    { id: 'tidy-lb-spinner' } );
        img      = el( 'img',    { id: 'tidy-lb-img' } );
        caption  = el( 'p',      { id: 'tidy-lb-caption' } );
        closeBtn = el( 'button', { id: 'tidy-lb-close', 'aria-label': 'Close', textContent: '×' } );
        prevBtn  = el( 'button', { id: 'tidy-lb-prev',  'aria-label': 'Previous' } );
        nextBtn  = el( 'button', { id: 'tidy-lb-next',  'aria-label': 'Next' } );

        prevBtn.innerHTML = '&#8249;';
        nextBtn.innerHTML = '&#8250;';

        overlay.appendChild( spinner );
        overlay.appendChild( img );
        overlay.appendChild( caption );
        overlay.appendChild( closeBtn );
        overlay.appendChild( prevBtn );
        overlay.appendChild( nextBtn );
        document.body.appendChild( overlay );

        closeBtn.addEventListener( 'click', close );
        prevBtn.addEventListener(  'click', function ( e ) { e.stopPropagation(); step( -1 ); } );
        nextBtn.addEventListener(  'click', function ( e ) { e.stopPropagation(); step(  1 ); } );
        overlay.addEventListener(  'click', function ( e ) {
            if ( e.target === overlay || e.target === img ) close();
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( ! overlay.classList.contains( 'is-open' ) ) return;
            if ( e.key === 'Escape' )     close();
            if ( e.key === 'ArrowLeft' )  step( -1 );
            if ( e.key === 'ArrowRight' ) step(  1 );
        } );

        overlay.addEventListener( 'touchstart', function ( e ) {
            touchStartX = e.changedTouches[0].clientX;
        }, { passive: true } );

        overlay.addEventListener( 'touchend', function ( e ) {
            var dx = e.changedTouches[0].clientX - touchStartX;
            if ( Math.abs( dx ) > 50 ) step( dx < 0 ? 1 : -1 );
        } );
    }

    function indexLinks() {
        var links = document.querySelectorAll( 'a.tidy-lb' );
        links.forEach( function ( a ) {
            var group = a.dataset.gallery || '__solo__';
            if ( ! groups[ group ] ) groups[ group ] = [];
            var idx = groups[ group ].length;
            groups[ group ].push( { href: a.href, caption: a.dataset.caption || '' } );
            a.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                open( group, idx );
            } );
        } );
    }

    function open( group, index ) {
        current.group = group;
        current.index = index;
        overlay.classList.add( 'is-open' );
        document.body.style.overflow = 'hidden';
        loadImage();
    }

    function close() {
        overlay.classList.remove( 'is-open', 'is-loading' );
        img.src = '';
        document.body.style.overflow = '';
    }

    function step( dir ) {
        var list = groups[ current.group ];
        if ( ! list ) return;
        var next = current.index + dir;
        if ( next < 0 || next >= list.length ) return;
        current.index = next;
        loadImage();
    }

    function loadImage() {
        var list  = groups[ current.group ];
        var entry = list[ current.index ];

        overlay.classList.add( 'is-loading' );
        img.src = '';

        var tmp = new Image();
        tmp.onload = function () {
            img.src = tmp.src;
            overlay.classList.remove( 'is-loading' );
        };
        tmp.onerror = function () {
            overlay.classList.remove( 'is-loading' );
        };
        tmp.src = entry.href;

        caption.textContent = entry.caption;
        caption.style.display = entry.caption ? '' : 'none';

        prevBtn.classList.toggle( 'is-hidden', current.index === 0 );
        nextBtn.classList.toggle( 'is-hidden', current.index === list.length - 1 );
    }

    function el( tag, props ) {
        var node = document.createElement( tag );
        Object.keys( props ).forEach( function ( k ) {
            if ( k === 'textContent' ) node.textContent = props[ k ];
            else node.setAttribute( k, props[ k ] );
        } );
        return node;
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();
