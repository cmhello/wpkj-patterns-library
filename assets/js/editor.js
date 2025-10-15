( function( wp ) {
    const { registerPlugin } = wp.plugins || {};
    // Prefer wp.editPost for SlotFill components in WP 6.8.3; fallback to wp.editor
    const editorPkg = wp.editPost || wp.editor || {};
    const { Modal, Button, TextControl, Spinner, PanelBody, Icon, CheckboxControl, SelectControl } = wp.components || {};
    const { createElement: el, Fragment, useState, useEffect, useRef } = wp.element || {};
    const { __ } = wp.i18n || ((s)=>s);
    const domReady = ( wp.domReady || ( fn => fn() ) );

    if ( ! registerPlugin ) {
        console.warn( '[WPKJ] plugins API missing, skipping UI init.' );
        return;
    }

    const cfg = window.WPKJPatternsConfig || { apiBase: '', jwt: '', restNonce: '', activeSlugs: [], canInstallPlugins: false, adminUrlPluginInstall: '', adminUrlPlugins: '' };
    const PER_PAGE = 20;
    const FAV_KEY = 'wpkj_pl_favorites_ids';
    const IMPORT_HISTORY_KEY = 'wpkj_pl_import_history';
    const SEARCH_HISTORY_KEY = 'wpkj_pl_search_history';

    const fetchJSON = async ( path, params = {}, opts = {} ) => {
        const url = new URL( (cfg.apiBase || '') + path, window.location.origin );
        const method = ( opts && opts.method ) ? opts.method : 'GET';
        let body;
        if ( method === 'GET' ) {
            Object.entries( params ).forEach( ([k,v]) => {
                if ( Array.isArray( v ) ) {
                    v.forEach( (val) => url.searchParams.append( k + '[]', val ) );
                } else if ( v !== undefined && v !== null ) {
                    url.searchParams.set( k, v );
                }
            } );
        } else {
            body = JSON.stringify( params || {} );
        }
        const headers = { 'Accept': 'application/json' };
        if ( method !== 'GET' ) headers['Content-Type'] = 'application/json';
        if ( cfg.jwt ) headers['Authorization'] = 'Bearer ' + cfg.jwt;
        try {
            const res = await fetch( url.toString(), { method, headers, body } );
            if ( ! res.ok ) {
                if ( opts && opts.silent ) return [];
                throw new Error( 'HTTP ' + res.status );
            }
            return res.json();
        } catch (e) {
            if ( opts && opts.silent ) return [];
            throw e;
        }
    };

    // Library plugin REST base helper (user favorites stored locally on this site)
    const fetchPL = async ( path, params = {}, opts = {} ) => {
        const base = '/wp-json/wpkj-pl/v1';
        const url = new URL( base + path, window.location.origin );
        const method = ( opts && opts.method ) ? opts.method : 'GET';
        let body;
        if ( method === 'GET' ) {
            Object.entries( params ).forEach( ([k,v]) => {
                if ( Array.isArray( v ) ) {
                    v.forEach( (val) => url.searchParams.append( k + '[]', val ) );
                } else if ( v !== undefined && v !== null ) {
                    url.searchParams.set( k, v );
                }
            } );
        } else {
            body = JSON.stringify( params || {} );
        }
        const headers = { 'Accept': 'application/json' };
        if ( method !== 'GET' ) headers['Content-Type'] = 'application/json';
        if ( cfg.restNonce ) headers['X-WP-Nonce'] = cfg.restNonce;
        try {
            const res = await fetch( url.toString(), { method, headers, body } );
            if ( ! res.ok ) {
                if ( opts && opts.silent ) return [];
                throw new Error( 'HTTP ' + res.status );
            }
            return res.json();
        } catch (e) {
            if ( opts && opts.silent ) return [];
            throw e;
        }
    };

    // Only use the agreed fields
    const getThumbFromItem = ( it ) => ( it && typeof it.featured_image === 'string' ) ? it.featured_image : '';

    const readFavorites = () => {
        try {
            const raw = window.localStorage.getItem( FAV_KEY );
            const arr = raw ? JSON.parse( raw ) : [];
            return Array.isArray( arr ) ? arr : [];
        } catch (e) { return []; }
    };
    const writeFavorites = ( ids ) => {
        try { window.localStorage.setItem( FAV_KEY, JSON.stringify( ids ) ); } catch (e) {}
    };

    const readImportHistory = () => {
        try {
            const raw = window.localStorage.getItem( IMPORT_HISTORY_KEY );
            const arr = raw ? JSON.parse( raw ) : [];
            return Array.isArray( arr ) ? arr : [];
        } catch (e) { return []; }
    };
    const writeImportHistory = ( list ) => {
        try { window.localStorage.setItem( IMPORT_HISTORY_KEY, JSON.stringify( list ) ); } catch (e) {}
    };
    const readSearchHistory = () => {
        try {
            const raw = window.localStorage.getItem( SEARCH_HISTORY_KEY );
            const arr = raw ? JSON.parse( raw ) : [];
            return Array.isArray( arr ) ? arr : [];
        } catch (e) { return []; }
    };
    const writeSearchHistory = ( list ) => {
        try { window.localStorage.setItem( SEARCH_HISTORY_KEY, JSON.stringify( list ) ); } catch (e) {}
    };

    const mergeUniqueById = ( prev, next ) => {
        const seen = new Set( prev.map( x => x.id ) );
        let added = 0;
        const merged = [ ...prev ];
        next.forEach( x => { if ( ! seen.has( x.id ) ) { seen.add( x.id ); merged.push( x ); added++; } } );
        return { merged, added };
    };

    const insertContent = ( html ) => {
        try {
            const blocks = ( wp.blocks && wp.blocks.rawHandler ) ? wp.blocks.rawHandler( { HTML: html } ) : ( wp.blocks && wp.blocks.parse ? wp.blocks.parse( html ) : [] );
            if ( blocks && blocks.length ) {
                wp.data.dispatch( 'core/editor' ).insertBlocks( blocks );
            }
        } catch (e) {
            console.error('[WPKJ] insert error', e);
        }
    };

    const PatternsModal = ( { isOpen, onClose } ) => {
        const [ query, setQuery ] = useState( '' );
        const [ loading, setLoading ] = useState( false );
        const [ error, setError ] = useState( '' );
        const [ items, setItems ] = useState( [] );
        const [ page, setPage ] = useState( 1 );
        const pageRef = useRef( 1 );
        const [ categories, setCategories ] = useState( [] );
        const [ types, setTypes ] = useState( [] );
        const [ selectedCategories, setSelectedCategories ] = useState( [] );
        const [ selectedTypes, setSelectedTypes ] = useState( [] );
        const [ orderBy, setOrderBy ] = useState( 'date' );
        const [ orderDir, setOrderDir ] = useState( 'DESC' );
        const [ activeTab, setActiveTab ] = useState( 'patterns' );
        const [ favorites, setFavorites ] = useState( readFavorites() );
        const [ onlyFavorites, setOnlyFavorites ] = useState( false );
        const [ hasMore, setHasMore ] = useState( true );
        const [ skeletonCount, setSkeletonCount ] = useState( 0 );
        const [ importHistory, setImportHistory ] = useState( readImportHistory() );
        const [ searchHistory, setSearchHistory ] = useState( readSearchHistory() );
        const [ deps, setDeps ] = useState( [] );
        const [ depsLoading, setDepsLoading ] = useState( false );
        
        // Preview is handled via external link, no in-modal preview state

        // Simple debounce for search and filters
        const debounce = (fn, wait = 300) => {
            let t;
            return (...args) => {
                clearTimeout( t );
                t = setTimeout( () => fn( ...args ), wait );
            };
        };

        const load = async ( reset = false ) => {
            setLoading( true );
            setError( '' );
            try {
                const requestPage = reset ? 1 : ( pageRef.current + 1 );
                const trimmed = (query || '').trim();
                const isSearch = trimmed.length > 0;
                const params = { per_page: PER_PAGE, page: requestPage, orderby: orderBy, order: orderDir };
                if ( isSearch ) params['q'] = trimmed;
                if ( selectedCategories && selectedCategories.length ) params['category'] = selectedCategories;
                if ( selectedTypes && selectedTypes.length ) params['type'] = selectedTypes;
                setSkeletonCount( reset ? PER_PAGE : Math.min( 8, PER_PAGE ) );
                const data = await fetchJSON( isSearch ? '/search' : '/patterns', params );
                // Record search history only for first page of a new search
                if ( isSearch && requestPage === 1 ) {
                    setSearchHistory( prev => {
                        const exists = new Set( prev.map( x => (x||'') ) );
                        if ( ! exists.has( trimmed ) ) {
                            const next = [ trimmed, ...prev ].slice( 0, 10 );
                            writeSearchHistory( next );
                            return next;
                        }
                        return prev;
                    } );
                }
                const prev = reset ? [] : items;
                const { merged, added } = mergeUniqueById( prev, Array.isArray( data ) ? data : [] );
                setItems( merged );
                setPage( requestPage );
                pageRef.current = requestPage;
                setHasMore( added > 0 && ( Array.isArray( data ) ? data.length >= PER_PAGE : false ) );
            } catch ( e ) {
                setError( e.message || 'Network error' );
            } finally {
                setLoading( false );
            }
        };

        const loadTaxonomies = async () => {
            try {
                const [ cats, tys ] = await Promise.all([
                    fetchJSON( '/categories', {}, { silent: true } ),
                    fetchJSON( '/types', {}, { silent: true } )
                ]);
                setCategories( Array.isArray( cats ) ? cats : [] );
                setTypes( Array.isArray( tys ) ? tys : [] );
            } catch (e) {
                // ignore taxonomy load errors
            }
        };

        const loadDependencies = async () => {
            setDepsLoading( true );
            try {
                const list = await fetchPL( '/dependencies', {}, { silent: true } );
                const normalized = Array.isArray( list ) ? list.map( (d) => {
                    if ( typeof d === 'string' ) return { slug: d, name: d };
                    const slug = d && d.info && d.info.slug ? d.info.slug : ( d && d.slug ? d.slug : '' );
                    const name = d && d.name ? d.name : ( slug || '' );
                    const required = !!( d && d.info && d.info.required );
                    return { slug, name, required };
                } ) : [];
                setDeps( normalized.filter( x => x.slug ) );
            } catch(e) {
                setDeps( [] );
            } finally {
                setDepsLoading( false );
            }
        };

        useEffect( () => {
            if ( isOpen ) {
                loadTaxonomies();
                loadDependencies();
                // Merge server favorites into local on open
                ( async () => {
                    try {
                        const list = await fetchPL( '/favorites', {}, { silent: true } );
                        if ( Array.isArray( list ) ) {
                            setFavorites( (prev) => {
                                const merged = Array.from( new Set( [ ...prev, ...list ] ) );
                                writeFavorites( merged );
                                return merged;
                            } );
                        }
                    } catch(e) {}
                } )();
                setHasMore( true );
                setItems( [] );
                setPage( 1 );
                pageRef.current = 1;
                load( true );
            }
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [ isOpen ] );

        // Debounced reload when query or filters change
        useEffect( () => {
            if ( ! isOpen ) return;
            const debounced = debounce( () => {
                setHasMore( true );
                setItems( [] );
                setPage( 1 );
                pageRef.current = 1;
                load( true );
            }, 300 );
            debounced();
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [ query, selectedCategories.join(','), selectedTypes.join(','), orderBy, orderDir ] );

        return el( Fragment, null,
            isOpen && el( Modal, {
                title: __( 'Patterns', 'wpkj-patterns-library' ),
                onRequestClose: onClose,
                className: 'wpkj-pl-modal',
            },
                el( 'div', { className: 'wpkj-pl-topbar' },
                    el( 'div', { className: 'wpkj-pl-tabs' },
                        el( Button, { isSecondary: activeTab !== 'patterns', isPrimary: activeTab === 'patterns', onClick: () => setActiveTab('patterns') }, __( 'Patterns', 'wpkj-patterns-library' ) ),
                        el( Button, { isSecondary: activeTab !== 'pages', disabled: true, onClick: () => setActiveTab('pages') }, __( 'Pages', 'wpkj-patterns-library' ) )
                    )
                ),
                el( 'div', { className: 'wpkj-pl-layout' },
                    el( 'aside', { className: 'wpkj-pl-sidebar' },
                        el( TextControl, {
                            value: query,
                            placeholder: __( 'Search', 'wpkj-patterns-library' ),
                            onChange: (v) => setQuery( v ),
                            onKeyDown: (e) => { if ( e.key === 'Enter' ) { setHasMore( true ); setItems( [] ); setPage( 1 ); pageRef.current = 1; load( true ); } },
                        } ),
                        ( searchHistory && searchHistory.length ) ? el( 'div', { className: 'wpkj-pl-search-history' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Recent searches', 'wpkj-patterns-library' ) ),
                            el( 'div', { className: 'wpkj-pl-search-chips' },
                                searchHistory.slice( 0, 6 ).map( (qv, idx) => el( Button, { key: 'q-'+idx, isSecondary: true, onClick: () => { setQuery( qv ); setHasMore( true ); setItems( [] ); setPage( 1 ); pageRef.current = 1; load( true ); } }, qv ) )
                            ),
                            el( Button, { isTertiary: true, onClick: () => { setSearchHistory( [] ); writeSearchHistory( [] ); } }, __( 'Clear', 'wpkj-patterns-library' ) )
                        ) : null,
                        el( 'div', { className: 'wpkj-pl-quick' },
                            el( SelectControl, {
                                label: __( 'Order by', 'wpkj-patterns-library' ),
                                value: orderBy,
                                options: [
                                    { label: __( 'Date', 'wpkj-patterns-library' ), value: 'date' },
                                    { label: __( 'Title', 'wpkj-patterns-library' ), value: 'title' },
                                    { label: __( 'Popular', 'wpkj-patterns-library' ), value: 'popular' },
                                ],
                                onChange: (val) => setOrderBy( val )
                            } ),
                            el( SelectControl, {
                                label: __( 'Order', 'wpkj-patterns-library' ),
                                value: orderDir,
                                options: [
                                    { label: 'DESC', value: 'DESC' },
                                    { label: 'ASC', value: 'ASC' },
                                ],
                                onChange: (val) => setOrderDir( val )
                            } )
                        ),
                        el( Button, { className: 'wpkj-pl-fav' , isSecondary: !onlyFavorites, isPrimary: onlyFavorites, onClick: () => setOnlyFavorites( !onlyFavorites ) }, __( 'Favorites', 'wpkj-patterns-library' ) ),
                        el( 'div', { className: 'wpkj-pl-filter-group' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Types', 'wpkj-patterns-library' ) ),
                            types && types.length ? types.map( (t) => el( CheckboxControl, {
                                key: t.id,
                                label: (t.name || '') + ' (' + (t.count || 0) + ')',
                                checked: selectedTypes.includes( t.id ),
                                onChange: (checked) => {
                                    const val = t.id;
                                    setSelectedTypes( checked ? [ ...selectedTypes, val ] : selectedTypes.filter( x => x !== val ) );
                                }
                            } ) ) : null
                        ),
                        el( 'div', { className: 'wpkj-pl-filter-group' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Categories', 'wpkj-patterns-library' ) ),
                            categories && categories.length ? categories.map( (c) => el( CheckboxControl, {
                                key: c.id,
                                label: (c.name || '') + ' (' + (c.count || 0) + ')',
                                checked: selectedCategories.includes( c.id ),
                                onChange: (checked) => {
                                    const val = c.id;
                                    setSelectedCategories( checked ? [ ...selectedCategories, val ] : selectedCategories.filter( x => x !== val ) );
                                }
                            } ) ) : null
                        ),
                        el( 'div', { className: 'wpkj-pl-filter-group' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Dependencies', 'wpkj-patterns-library' ) ),
                            depsLoading ? el( Spinner, null ) : ( deps && deps.length ? deps.map( (dep) => {
                                const isInstalled = Array.isArray( cfg.activeSlugs ) && cfg.activeSlugs.includes( dep.slug );
                                const installUrl = ( cfg.adminUrlPluginInstall || '/wp-admin/plugin-install.php?tab=search&s=' ) + encodeURIComponent( dep.slug );
                                const manageUrl = cfg.adminUrlPlugins || '/wp-admin/plugins.php';
                                const tryInstall = async () => {
                                    if ( window.wp && window.wp.updates && typeof window.wp.updates.installPlugin === 'function' && cfg.canInstallPlugins ) {
                                        try { await window.wp.updates.installPlugin( { slug: dep.slug } ); } catch(e) {}
                                    } else {
                                        window.open( installUrl, '_blank' );
                                    }
                                };
                                return el( 'div', { key: dep.slug, className: 'wpkj-pl-dep-row' },
                                    el( 'span', null, dep.name || dep.slug ),
                                    dep.required ? el( 'span', { style: { marginLeft: 8, color: '#a50' } }, __( 'Required', 'wpkj-patterns-library' ) ) : null,
                                    isInstalled ? el( 'span', { style: { marginLeft: 8, color: '#0a0' } }, __( 'Installed', 'wpkj-patterns-library' ) ) : el( Button, { isSecondary: true, onClick: tryInstall, style: { marginLeft: 8 } }, __( 'Install', 'wpkj-patterns-library' ) ),
                                    el( 'a', { href: manageUrl, target: '_blank', rel: 'noopener', style: { marginLeft: 8 } }, __( 'Manage', 'wpkj-patterns-library' ) )
                                );
                            } ) : el( 'div', { style: { color: '#666' } }, __( 'No dependencies', 'wpkj-patterns-library' ) ) )
                        ),
                        ( importHistory && importHistory.length ) ? el( 'div', { className: 'wpkj-pl-import-history' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Recent imports', 'wpkj-patterns-library' ) ),
                            el( 'div', { className: 'wpkj-pl-import-list' },
                                importHistory.slice(0,6).map( (it, idx) => el( Fragment, { key: it.id || ('imp-'+idx) },
                                    el( 'div', { className: 'wpkj-pl-import-item-title' }, it.title || ('#' + it.id) ),
                                    el( Button, { isSecondary: true, onClick: async () => { if ( it.content ) { insertContent( it.content ); } else { try { const det = await fetchJSON( `/patterns/${it.id}`, {}, { silent: true } ); if ( det && det.content ) insertContent( det.content ); } catch(e) {} } } }, __( 'Insert', 'wpkj-patterns-library' ) )
                                ) )
                            ),
                            el( Button, { isTertiary: true, onClick: () => { setImportHistory( [] ); writeImportHistory( [] ); } }, __( 'Clear', 'wpkj-patterns-library' ) )
                        ) : null
                    ),
                    el( 'div', { className: 'wpkj-pl-main' },
                        el( 'div', { className: 'wpkj-pl-heading' }, __( 'All Templates', 'wpkj-patterns-library' ) ),
                        error && el( 'div', { className: 'notice notice-error' }, error ),
                        el( 'div', { className: 'wpkj-pl-content' },
                            el( 'div', { className: 'wpkj-pl-grid' },
                                ( () => {
                                    const displayItems = onlyFavorites ? items.filter( x => favorites.includes( x.id ) ) : items;
                                    if ( loading ) {
                                        const count = skeletonCount || Math.min( PER_PAGE, 12 );
                                        return Array.from( { length: count } ).map( ( _, idx ) => el( 'div', { key: 'skel-' + idx, className: 'wpkj-pl-card skeleton' },
                                            el( 'div', { className: 'wpkj-pl-card-media' },
                                                el( 'div', { className: 'wpkj-pl-thumb skel' } )
                                            ),
                                            el( 'div', { className: 'wpkj-pl-card-title skel' }, ' ' )
                                        ) );
                                    }
                                    if ( ! displayItems.length ) {
                                        return el( 'div', { className: 'wpkj-pl-empty' }, __( 'No results found.', 'wpkj-patterns-library' ) );
                                    }
                                    return displayItems.map( ( it ) => {
                                        const thumb = getThumbFromItem( it );
                                        const link = it.link || '#';
                                        const isFav = favorites.includes( it.id );
                                        const toggleFav = async () => {
                                            const next = isFav ? favorites.filter( id => id !== it.id ) : [ ...favorites, it.id ];
                                            setFavorites( next );
                                            writeFavorites( next );
                                            // Sync to server if logged in
                                            try {
                                                const action = isFav ? 'remove' : 'add';
                                                await fetchPL( '/favorites', { id: it.id, action }, { method: 'POST', silent: true } );
                                                // Skip remote favorite call to avoid 400 errors
                                            } catch(e) {}
                                        };
                                        return el( 'div', { key: it.id, className: 'wpkj-pl-card' },
                                            el( 'div', { className: 'wpkj-pl-card-media' },
                                                thumb ? el( 'img', { src: thumb, alt: it.title || ('#' + it.id), className: 'wpkj-pl-thumb', loading: 'lazy', onError: (e) => { e.currentTarget.style.visibility = 'hidden'; } } ) : el( 'div', { className: 'wpkj-pl-thumb' }, '' ),
                                                el( 'div', { className: 'wpkj-pl-card-overlay' },
                                                    el( 'a', { href: link, target: '_blank', rel: 'noopener', className: 'components-button is-secondary' }, __( 'Preview', 'wpkj-patterns-library' ) ),
                                                    el( Button, { isPrimary: true, onClick: async () => { insertContent( it.content || '' ); try { await fetchJSON( `/patterns/${it.id}/import`, {}, { method: 'POST', silent: true } ); } catch(e) {} setImportHistory( prev => { const entry = { id: it.id, title: it.title || ('#' + it.id), link: it.link || '#', content: it.content || '', ts: Date.now() }; const dedup = prev.filter( x => x.id !== entry.id ); const next = [ entry, ...dedup ].slice(0,10); writeImportHistory( next ); return next; } ); } }, __( 'Import', 'wpkj-patterns-library' ) ),
                                                    el( Button, { className: 'wpkj-pl-fav-toggle' + ( isFav ? ' is-active' : '' ), isSecondary: true, onClick: toggleFav, 'aria-label': __( 'Favorite', 'wpkj-patterns-library' ), title: __( 'Favorite', 'wpkj-patterns-library' ) }, isFav ? '★' : '☆' )
                                                )
                                            ),
                                            el( 'div', { className: 'wpkj-pl-card-title' }, it.title || ('#' + it.id) )
                                        );
                                    } );
                                } )()
                            )
                        )
                    )
                ),
                el( 'div', { className: 'wpkj-pl-footer' },
                    el( Button, { isSecondary: true, disabled: loading || ! hasMore, onClick: () => load( false ) }, hasMore ? __( 'Load more', 'wpkj-patterns-library' ) : __( 'No more', 'wpkj-patterns-library' ) )
                )
            )
        );
    };

    domReady( () => {
        // Minimal implementation: directly insert a button into left toolbar once
        const HeaderTrigger = () => {
            const [ open, setOpen ] = wp.element.useState( false );
            return el( Fragment, null,
                el( wp.components.Button, {
                    className: 'components-button pat-library-button',
                    isPrimary: true,
                    onClick: (e) => { e.preventDefault(); setOpen( true ); },
                }, __( 'Patterns Library', 'wpkj-patterns-library' ) ),
                open && el( PatternsModal, { isOpen: open, onClose: () => setOpen( false ) } )
            );
        };

        const mountIntoLeft = () => {
            const container = document.querySelector( '#editor .editor-header__center' );
            if ( ! container ) return false;
            const mount = document.createElement( 'div' );
            mount.className = 'wpkj-pl-header-trigger';
            // Insert at the beginning of the container
            container.insertBefore( mount, container.lastChild );
            const createRoot = wp.element && wp.element.createRoot ? wp.element.createRoot : null;
            const render = wp.element && wp.element.render ? wp.element.render : null;
            if ( createRoot ) {
                createRoot( mount ).render( el( HeaderTrigger, null ) );
            } else if ( render ) {
                render( el( HeaderTrigger, null ), mount );
            }
            return true;
        };

        // Single attempt, with a minimal delayed retry
        if ( ! mountIntoLeft() ) {
            setTimeout( mountIntoLeft, 300 );
        }
    } );
} )( window.wp || {} );