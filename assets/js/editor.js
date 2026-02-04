( function( wp ) {
    const { registerPlugin } = wp.plugins || {};
    // Prefer wp.editPost for SlotFill components in WP 6.8.3; fallback to wp.editor
    const editorPkg = wp.editPost || wp.editor || {};
    const { Modal, Button, TextControl, Spinner, PanelBody, Icon, CheckboxControl, SelectControl } = wp.components || {};
    const { createElement: el, Fragment, useState, useEffect, useRef } = wp.element || {};
    const { __ } = wp.i18n || ((s)=>s);
    const domReady = ( wp.domReady || ( fn => fn() ) );
    // Track last known insertion context to support insertion after selection is lost
    let lastInsertionIndex = undefined;
    let lastInsertionRoot = undefined;

    if ( ! registerPlugin ) {
        console.warn( '[WPKJ] plugins API missing, skipping UI init.' );
        return;
    }

    const cfg = window.WPKJPatternsConfig || { restNonce: '', activeSlugs: [], canInstallPlugins: false, adminUrlPluginInstall: '', adminUrlPlugins: '' };
    const PER_PAGE = 18;
    const FAV_KEY = 'wpkj_pl_favorites_ids';
    const IMPORT_HISTORY_KEY = 'wpkj_pl_import_history';
    const SEARCH_HISTORY_KEY = 'wpkj_pl_search_history';
    const CACHE_PREFIX = 'wpkj_pl_cache_';
    const CACHE_TTL = 15 * 60 * 1000; // 15 minutes in milliseconds

    // Removed legacy fetchJSON (direct remote calls). All requests go via local REST proxy.

    // SessionStorage cache helpers
    const getCached = ( key ) => {
        try {
            const cached = window.sessionStorage.getItem( CACHE_PREFIX + key );
            if ( ! cached ) return null;
            const data = JSON.parse( cached );
            // Check expiry
            if ( data.expiry && Date.now() > data.expiry ) {
                window.sessionStorage.removeItem( CACHE_PREFIX + key );
                return null;
            }
            return data.value;
        } catch (e) {
            return null;
        }
    };
    const setCached = ( key, value, ttl = CACHE_TTL ) => {
        try {
            const data = {
                value: value,
                expiry: Date.now() + ttl
            };
            window.sessionStorage.setItem( CACHE_PREFIX + key, JSON.stringify( data ) );
        } catch (e) {
            // SessionStorage full or disabled, silently fail
        }
    };
    const clearCache = () => {
        try {
            const keys = [];
            for ( let i = 0; i < window.sessionStorage.length; i++ ) {
                const key = window.sessionStorage.key( i );
                if ( key && key.startsWith( CACHE_PREFIX ) ) {
                    keys.push( key );
                }
            }
            keys.forEach( k => window.sessionStorage.removeItem( k ) );
        } catch (e) {}
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

        // Generate cache key for GET requests
        const cacheKey = method === 'GET' ? path + '_' + JSON.stringify( params ) : null;
        
        // Check cache for GET requests (unless explicitly bypassed)
        if ( method === 'GET' && ! ( opts && opts.noCache ) ) {
            const cached = getCached( cacheKey );
            if ( cached !== null ) {
                return cached;
            }
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
            const data = await res.json();
            
            // Cache successful GET responses
            if ( method === 'GET' && cacheKey && data ) {
                setCached( cacheKey, data );
            }
            
            return data;
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
        let firstNewId;
        const merged = [ ...prev ];
        next.forEach( x => {
            if ( ! seen.has( x.id ) ) {
                seen.add( x.id );
                merged.push( x );
                added++;
                if ( firstNewId === undefined ) firstNewId = x.id;
            }
        } );
        return { merged, added, firstNewId };
    };

    const insertContent = ( html ) => {
        try {
            const blocks = ( wp.blocks && wp.blocks.rawHandler ) ? wp.blocks.rawHandler( { HTML: html } ) : ( wp.blocks && wp.blocks.parse ? wp.blocks.parse( html ) : [] );
            if ( blocks && blocks.length ) {
                const beDispatch = wp.data && wp.data.dispatch ? wp.data.dispatch( 'core/block-editor' ) : null;
                const beSelect = wp.data && wp.data.select ? wp.data.select( 'core/block-editor' ) : null;
                if ( beDispatch && beSelect && typeof beDispatch.insertBlocks === 'function' ) {
                    let index;
                    let rootCid;
                    try {
                        let sel = beSelect.getSelectionStart ? beSelect.getSelectionStart() : null;
                        if ( ( ! sel || ! sel.clientId ) && beSelect.getSelectedBlockClientId ) {
                            const cid = beSelect.getSelectedBlockClientId();
                            if ( cid ) sel = { clientId: cid };
                        }
                        if ( sel && sel.clientId ) {
                            rootCid = beSelect.getBlockRootClientId ? beSelect.getBlockRootClientId( sel.clientId ) : undefined;
                            if ( beSelect.getBlockIndex ) {
                                const currentIndex = beSelect.getBlockIndex( sel.clientId, rootCid );
                                index = ( typeof currentIndex === 'number' ? ( currentIndex + 1 ) : undefined );
                            }
                        }
                    } catch(e) { index = undefined; }
                    // Fallback to remembered insertion context
                    if ( typeof index !== 'number' && typeof lastInsertionIndex === 'number' ) {
                        index = lastInsertionIndex;
                    }
                    if ( ! rootCid && lastInsertionRoot ) {
                        rootCid = lastInsertionRoot;
                    }
                    // Insert at computed index (after current selection) or fallback
                    if ( typeof index === 'number' || rootCid ) {
                        beDispatch.insertBlocks( blocks, index, rootCid );
                    } else {
                        beDispatch.insertBlocks( blocks );
                    }
                    try {
                        if ( typeof index === 'number' ) {
                            lastInsertionIndex = index + blocks.length;
                            lastInsertionRoot = rootCid;
                        }
                    } catch(e) {}
                } else {
                    // Fallback to legacy store
                    const legacy = wp.data && wp.data.dispatch ? wp.data.dispatch( 'core/editor' ) : null;
                    if ( legacy && typeof legacy.insertBlocks === 'function' ) {
                        legacy.insertBlocks( blocks );
                    }
                }
            }
        } catch (e) {
            console.error('[WPKJ] insert error', e);
        }
    };

    // Sideload media from pattern content
    const sideloadMedia = async ( pattern, onProgress ) => {
        try {
            const response = await fetchPL( '/sideload-media', {
                content: pattern.content || '',
                pattern_id: pattern.id
            }, { method: 'POST' } );

            return response;
        } catch (e) {
            console.error('[WPKJ] sideload error', e);
            throw e;
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
        const [ isAppending, setIsAppending ] = useState( false );
        const gridRef = useRef( null );
        const [ importHistory, setImportHistory ] = useState( readImportHistory() );
        const [ searchHistory, setSearchHistory ] = useState( readSearchHistory() );
        // Dependencies overlay state
        const [ depsStatus, setDepsStatus ] = useState( null );
        const [ depsChecking, setDepsChecking ] = useState( false );
        const [ depsInstalling, setDepsInstalling ] = useState( false );
        const [ depsError, setDepsError ] = useState( '' );
        // Media sideload state
        const [ sideloadProgress, setSideloadProgress ] = useState( null ); // { current, total, text }
        const [ confirmImportPattern, setConfirmImportPattern ] = useState( null ); // Pattern to import
        const [ importStatus, setImportStatus ] = useState( null ); // 'importing' | 'downloading' | 'success' | 'error'
        const [ importMessage, setImportMessage ] = useState( '' ); // Status message to display
        
        // Preview is handled via external link, no in-modal preview state

        // Simple debounce for search and filters
        const debounce = (fn, wait = 500) => {
            let t;
            return (...args) => {
                clearTimeout( t );
                t = setTimeout( () => fn( ...args ), wait );
            };
        };

        const load = async ( reset = false, queryOverride = null ) => {
            setLoading( true );
            setError( '' );
            setIsAppending( ! reset );
            try {
                const requestPage = reset ? 1 : ( pageRef.current + 1 );
                const trimmed = ( ( queryOverride !== null ? queryOverride : (query || '') ) ).trim();
                const isSearch = trimmed.length >= 2;
                const params = { per_page: PER_PAGE, page: requestPage, orderby: orderBy, order: orderDir };
                if ( isSearch ) params['q'] = trimmed;
                if ( selectedCategories && selectedCategories.length ) params['category'] = selectedCategories;
                if ( selectedTypes && selectedTypes.length ) params['type'] = selectedTypes;
                setSkeletonCount( reset ? PER_PAGE : PER_PAGE );
                const data = await fetchPL( isSearch ? '/manager/search' : '/manager/patterns', params );
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
                const { merged, added, firstNewId } = mergeUniqueById( prev, Array.isArray( data ) ? data : [] );
                setItems( merged );
                setPage( requestPage );
                pageRef.current = requestPage;
                setHasMore( added > 0 && ( Array.isArray( data ) ? data.length >= PER_PAGE : false ) );
                // Smoothly scroll to first newly loaded item on "Load more"
                if ( ! reset && added > 0 && firstNewId !== undefined ) {
                    setTimeout( () => {
                        const sel = document.querySelector( '.wpkj-pl-grid .wpkj-pl-card[data-id="' + String( firstNewId ) + '"]' );
                        if ( sel && typeof sel.scrollIntoView === 'function' ) {
                            // Scroll with top alignment and offset for better UX
                            sel.scrollIntoView( { block: 'start', inline: 'nearest', behavior: 'smooth' } );
                            // Add small offset to avoid header overlap
                            const modalContent = sel.closest( '.components-modal__content' );
                            if ( modalContent ) {
                                setTimeout( () => {
                                    modalContent.scrollTop = Math.max( 0, modalContent.scrollTop - 20 );
                                }, 300 );
                            }
                        }
                    }, 100 );
                }
            } catch ( e ) {
                setError( e.message || 'Network error' );
            } finally {
                setLoading( false );
                setIsAppending( false );
            }
        };

        const loadTaxonomies = async ( forceRefresh = false ) => {
            try {
                const [ cats, tys ] = await Promise.all([
                    fetchPL( '/manager/categories', {}, { silent: true, noCache: forceRefresh } ),
                    fetchPL( '/manager/types', {}, { silent: true, noCache: forceRefresh } )
                ]);
                setCategories( Array.isArray( cats ) ? cats : [] );
                setTypes( Array.isArray( tys ) ? tys : [] );
            } catch (e) {
                // ignore taxonomy load errors
            }
        };

        const checkDepsStatus = async ( opts = {} ) => {
            setDepsChecking( true );
            setDepsError( '' );
            try {
                const force = !!opts.force;
                const status = await fetchPL( '/deps-status', force ? { no_cache: 1 } : {}, { silent: true } );
                if ( status && typeof status === 'object' ) {
                    setDepsStatus( status );
                } else {
                    setDepsStatus( { all_ready: true, required: [] } );
                }
            } catch (e) {
                setDepsError( e && e.message ? e.message : 'Deps check failed' );
                setDepsStatus( { all_ready: true, required: [] } );
            } finally {
                setDepsChecking( false );
            }
        };

        const installAllDeps = async () => {
            if ( depsInstalling ) return;
            setDepsInstalling( true );
            setDepsError( '' );
            try {
                const pending = (depsStatus && Array.isArray( depsStatus.required )) ? depsStatus.required.filter( x => !(x.installed && x.active) ).map( x => x.slug ) : [];
                const res = await fetchPL( '/deps-install', { slugs: pending }, { method: 'POST', silent: true } );
                // After install, re-check status with forced refresh
                await checkDepsStatus( { force: true } );
            } catch (e) {
                setDepsError( e && e.message ? e.message : 'Deps install failed' );
            } finally {
                setDepsInstalling( false );
            }
        };

        useEffect( () => {
            if ( isOpen ) {
                loadTaxonomies();
                // On open, read cached status (no force)
                checkDepsStatus( { force: false } );
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

        // Debounced reload when filters change (query does NOT auto-trigger)
        useEffect( () => {
            if ( ! isOpen ) return;
            const debounced = debounce( () => {
                setHasMore( true );
                setItems( [] );
                setPage( 1 );
                pageRef.current = 1;
                load( true );
            }, 500 );
            debounced();
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [ selectedCategories.join(','), selectedTypes.join(','), orderBy, orderDir ] );

        return el( Fragment, null,
            // Progress bar (floating top)
            sideloadProgress && el( 'div', { className: 'wpkj-pl-progress-bar' },
                el( 'div', { className: 'wpkj-pl-progress-text' }, sideloadProgress.text || __( 'Downloading...', 'wpkj-patterns-library' ) ),
                el( 'div', { className: 'wpkj-pl-progress-track' },
                    el( 'div', { 
                        className: 'wpkj-pl-progress-fill', 
                        style: { width: sideloadProgress.percent + '%' } 
                    } )
                )
            ),
            isOpen && el( Modal, {
                title: __( 'Patterns Library', 'wpkj-patterns-library' ),
                onRequestClose: onClose,
                className: 'wpkj-pl-modal',
            },
                // Dependencies overlay (only show when not ready AND dependencies exist)
                el( 'div', { className: 'wpkj-pl-deps-overlay' + ( (depsStatus && ! depsStatus.all_ready && Array.isArray( depsStatus.required ) && depsStatus.required.length > 0) ? ' is-active' : '' ) },
                    ( depsStatus && ! depsStatus.all_ready && Array.isArray( depsStatus.required ) && depsStatus.required.length > 0 ) ? el( 'div', { className: 'wpkj-pl-deps-panel' },
                        el( 'h3', null, __( 'Required Plugins', 'wpkj-patterns-library' ) ),
                        depsChecking ? el( Spinner, null ) : el( 'div', { className: 'wpkj-pl-deps-list' },
                            depsStatus.required.map( (r) => {
                                const st = (r.installed && r.active) ? __( 'Ready', 'wpkj-patterns-library' ) : ( r.installed ? __( 'Installed, inactive', 'wpkj-patterns-library' ) : __( 'Missing', 'wpkj-patterns-library' ) );
                                return el( 'div', { key: r.slug, className: 'wpkj-pl-deps-row' },
                                    el( 'span', null, r.name || r.slug ),
                                    el( 'span', { className: 'status' }, st )
                                );
                            } )
                        ),
                        depsError ? el( 'div', { className: 'notice notice-error' }, depsError ) : null,
                        el( 'div', { className: 'wpkj-pl-deps-actions' },
                            el( Button, { isPrimary: true, isBusy: depsInstalling, onClick: installAllDeps }, __( 'Install All', 'wpkj-patterns-library' ) ),
                            el( Button, { isSecondary: true, onClick: () => checkDepsStatus( { force: true } ) }, __( 'Recheck', 'wpkj-patterns-library' ) ),
                            el( 'a', { href: (cfg.adminUrlPlugins || '/wp-admin/plugins.php'), className: 'components-button is-tertiary', target: '_blank', rel: 'noopener' }, __( 'Manage Plugins', 'wpkj-patterns-library' ) )
                        )
                    ) : null
                ),
                // Confirm import overlay (similar to deps overlay)
                el( 'div', { className: 'wpkj-pl-confirm-overlay' + ( confirmImportPattern ? ' is-active' : '' ) },
                    confirmImportPattern ? el( 'div', { className: 'wpkj-pl-confirm-panel' },
                        // Show different UI based on import status
                        ! importStatus ? el( Fragment, null,
                            // Initial confirmation state
                            el( 'h3', null, __( 'Import Pattern', 'wpkj-patterns-library' ) ),
                            el( 'p', null, __( 'Download external images and videos to your media library?', 'wpkj-patterns-library' ) ),
                            el( 'p', { style: { fontSize: '13px', color: '#666', marginTop: '8px' } }, 
                                __( 'Note: Videos will be skipped and need manual replacement.', 'wpkj-patterns-library' ) 
                            ),
                            el( 'div', { className: 'wpkj-pl-confirm-actions' },
                                el( Button, { 
                                    isTertiary: true,
                                    onClick: () => {
                                        // Cancel - just close the overlay
                                        setConfirmImportPattern( null );
                                        setImportStatus( null );
                                        setImportMessage( '' );
                                    }
                                }, __( 'Cancel', 'wpkj-patterns-library' ) ),
                                el( Button, { 
                                    isSecondary: true,
                                    onClick: async () => {
                                        // Import without sideload
                                        const pattern = confirmImportPattern;
                                        setImportStatus( 'importing' );
                                        setImportMessage( __( 'Importing pattern...', 'wpkj-patterns-library' ) );
                                        
                                        try {
                                            insertContent( pattern.content || '' );
                                            
                                            // Update history
                                            try { 
                                                await fetchPL( `/manager/patterns/${pattern.id}`, {}, { method: 'POST', silent: true } ); 
                                            } catch(e) {}
                                            setImportHistory( prev => {
                                                const entry = { 
                                                    id: pattern.id, 
                                                    title: pattern.title || ('#' + pattern.id), 
                                                    link: pattern.link || '#', 
                                                    content: pattern.content || '', 
                                                    featured_image: getThumbFromItem( pattern ), 
                                                    ts: Date.now() 
                                                };
                                                const dedup = prev.filter( x => x.id !== entry.id );
                                                const next = [ entry, ...dedup ].slice(0,10);
                                                writeImportHistory( next );
                                                return next;
                                            } );
                                            
                                            // Show success
                                            setImportStatus( 'success' );
                                            setImportMessage( __( 'Pattern imported successfully!', 'wpkj-patterns-library' ) );
                                            
                                            // Auto close after 3 seconds
                                            setTimeout( () => {
                                                setConfirmImportPattern( null );
                                                setImportStatus( null );
                                                setImportMessage( '' );
                                            }, 3000 );
                                        } catch (e) {
                                            setImportStatus( 'error' );
                                            setImportMessage( __( 'Import failed. Please try again.', 'wpkj-patterns-library' ) );
                                            setTimeout( () => {
                                                setConfirmImportPattern( null );
                                                setImportStatus( null );
                                                setImportMessage( '' );
                                            }, 3000 );
                                        }
                                    }
                                }, __( 'No, Import Only', 'wpkj-patterns-library' ) ),
                                el( Button, { 
                                    isPrimary: true,
                                    onClick: async () => {
                                        // Import with sideload
                                        const pattern = confirmImportPattern;
                                        setImportStatus( 'downloading' );
                                        setImportMessage( __( 'Analyzing media...', 'wpkj-patterns-library' ) );
                                        
                                        try {
                                            const result = await sideloadMedia( pattern );
                                            
                                            if ( result && result.success ) {
                                                // Insert content (with replaced URLs)
                                                insertContent( result.content || pattern.content || '' );
                                                
                                                // Update history
                                                try { 
                                                    await fetchPL( `/manager/patterns/${pattern.id}`, {}, { method: 'POST', silent: true } ); 
                                                } catch(e) {}
                                                setImportHistory( prev => {
                                                    const entry = { 
                                                        id: pattern.id, 
                                                        title: pattern.title || ('#' + pattern.id), 
                                                        link: pattern.link || '#', 
                                                        content: result.content || pattern.content || '', 
                                                        featured_image: getThumbFromItem( pattern ), 
                                                        ts: Date.now() 
                                                    };
                                                    const dedup = prev.filter( x => x.id !== entry.id );
                                                    const next = [ entry, ...dedup ].slice(0,10);
                                                    writeImportHistory( next );
                                                    return next;
                                                } );
                                                
                                                // Build success message
                                                const stats = result.stats || {};
                                                let msg = __( 'Pattern imported successfully!', 'wpkj-patterns-library' );
                                                const details = [];
                                                if ( stats.downloaded > 0 ) {
                                                    details.push( stats.downloaded + ' ' + __( 'media files downloaded', 'wpkj-patterns-library' ) );
                                                }
                                                if ( stats.failed > 0 ) {
                                                    details.push( stats.failed + ' ' + __( 'failed', 'wpkj-patterns-library' ) );
                                                }
                                                if ( stats.videos > 0 ) {
                                                    details.push( stats.videos + ' ' + __( 'video(s) need manual replacement', 'wpkj-patterns-library' ) );
                                                }
                                                if ( details.length > 0 ) {
                                                    msg += '\n' + details.join( ', ' ) + '.';
                                                }
                                                
                                                setImportStatus( stats.failed > 0 ? 'warning' : 'success' );
                                                setImportMessage( msg );
                                                
                                                // Auto close after 3 seconds
                                                setTimeout( () => {
                                                    setConfirmImportPattern( null );
                                                    setImportStatus( null );
                                                    setImportMessage( '' );
                                                }, 3000 );
                                            } else {
                                                throw new Error( 'Sideload failed' );
                                            }
                                        } catch (e) {
                                            // Fallback: insert original content
                                            insertContent( pattern.content || '' );
                                            
                                            setImportStatus( 'error' );
                                            setImportMessage( __( 'Media download failed. Pattern imported with original URLs.', 'wpkj-patterns-library' ) );
                                            
                                            // Auto close after 3 seconds
                                            setTimeout( () => {
                                                setConfirmImportPattern( null );
                                                setImportStatus( null );
                                                setImportMessage( '' );
                                            }, 3000 );
                                        }
                                    }
                                }, __( 'Yes, Download Media', 'wpkj-patterns-library' ) )
                            )
                        ) : el( Fragment, null,
                            // Progress/Result state
                            el( 'div', { className: 'wpkj-pl-import-status' },
                                importStatus === 'importing' || importStatus === 'downloading' ? el( Spinner, null ) : null,
                                el( 'div', { 
                                    className: 'wpkj-pl-import-icon ' + importStatus,
                                    style: { fontSize: '48px', textAlign: 'center', margin: '20px 0' }
                                }, 
                                    importStatus === 'success' ? '✓' : ( importStatus === 'error' ? '✕' : ( importStatus === 'warning' ? '⚠' : '' ) )
                                ),
                                el( 'p', { 
                                    style: { 
                                        textAlign: 'center', 
                                        fontSize: '16px', 
                                        whiteSpace: 'pre-line',
                                        color: importStatus === 'error' ? '#d63638' : ( importStatus === 'warning' ? '#dba617' : '#00a32a' )
                                    } 
                                }, importMessage )
                            )
                        )
                    ) : null
                ),
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
                            placeholder: __( 'Type and press Enter', 'wpkj-patterns-library' ),
                            onChange: (v) => setQuery( v ),
                            __next40pxDefaultSize: true,
                            __nextHasNoMarginBottom: true,
                            onKeyDown: (e) => {
                                if ( e.key === 'Enter' ) {
                                    const trimmed = (query || '').trim();
                                    if ( trimmed.length === 0 || trimmed.length >= 2 ) {
                                        setHasMore( true ); setItems( [] ); setPage( 1 ); pageRef.current = 1; load( true, query );
                                    }
                                }
                            },
                        } ),
                        ( searchHistory && searchHistory.length ) ? el( 'div', { className: 'wpkj-pl-search-history' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Recent searches', 'wpkj-patterns-library' ) ),
                            el( 'div', { className: 'wpkj-pl-search-chips' },
                                searchHistory.slice( 0, 6 ).map( (qv, idx) => el( Button, { key: 'q-'+idx, isSecondary: true, onClick: () => { setQuery( qv ); setHasMore( true ); setItems( [] ); setPage( 1 ); pageRef.current = 1; load( true, qv ); } }, qv ) )
                            ),
                            el( Button, { isTertiary: true, onClick: () => { setSearchHistory( [] ); writeSearchHistory( [] ); } }, __( 'Clear', 'wpkj-patterns-library' ) )
                        ) : null,
                        el( 'div', { className: 'wpkj-pl-quick' },
                            el( SelectControl, {
                                label: __( 'Order by', 'wpkj-patterns-library' ),
                                value: orderBy,
                                __next40pxDefaultSize: true,
                                __nextHasNoMarginBottom: true,
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
                                __next40pxDefaultSize: true,
                                __nextHasNoMarginBottom: true,
                                options: [
                                    { label: 'DESC', value: 'DESC' },
                                    { label: 'ASC', value: 'ASC' },
                                ],
                                onChange: (val) => setOrderDir( val )
                            } )
                        ),
                        el( Button, { className: 'wpkj-pl-fav' , isSecondary: !onlyFavorites, isPrimary: onlyFavorites, onClick: () => setOnlyFavorites( !onlyFavorites ) }, __( 'Favorites', 'wpkj-patterns-library' ) ),
                        el( Button, { className: 'wpkj-pl-all', isSecondary: true, onClick: () => { setQuery(''); setSelectedCategories([]); setSelectedTypes([]); setOnlyFavorites(false); setHasMore(true); setItems([]); setPage(1); pageRef.current = 1; load(true, ''); } }, __( 'All Patterns', 'wpkj-patterns-library' ) ),
                        el( 'div', { className: 'wpkj-pl-filter-group' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Types', 'wpkj-patterns-library' ) ),
                            types && types.length ? types.map( (t) => el( CheckboxControl, {
                                key: t.id,
                                label: (t.name || '') + ' (' + (t.count || 0) + ')',
                                checked: selectedTypes.includes( t.id ),
                                __nextHasNoMarginBottom: true,
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
                                __nextHasNoMarginBottom: true,
                                onChange: (checked) => {
                                    const val = c.id;
                                    setSelectedCategories( checked ? [ ...selectedCategories, val ] : selectedCategories.filter( x => x !== val ) );
                                }
                            } ) ) : null
                        ),
                        // Dependencies list removed; handled via overlay
                        ( importHistory && importHistory.length ) ? el( 'div', { className: 'wpkj-pl-import-history' },
                            el( 'div', { className: 'wpkj-pl-filter-title' }, __( 'Recent imports', 'wpkj-patterns-library' ) ),
                            el( 'div', { className: 'wpkj-pl-import-list' },
                                importHistory.slice(0,6).map( (it, idx) => el( Fragment, { key: it.id || ('imp-'+idx) },
                                    el( 'div', { className: 'wpkj-pl-import-item-title', onMouseEnter: async () => {
                                        try {
                                            if ( it && it.id && ! it.featured_image ) {
                                                const det = await fetchPL( `/manager/patterns/${it.id}`, {}, { silent: true } );
                                                const thumb = det ? getThumbFromItem( det ) : '';
                                                if ( thumb ) {
                                                    setImportHistory( prev => {
                                                        const next = prev.map( x => x.id === it.id ? { ...x, featured_image: thumb } : x );
                                                        writeImportHistory( next );
                                                        return next;
                                                    } );
                                                }
                                            }
                                        } catch(e) {}
                                    } },
                                        el( 'span', null, it.title || ('#' + it.id) ),
                                        ( it && it.featured_image ? el( 'div', { className: 'wpkj-pl-import-preview' },
                                            el( 'img', { src: it.featured_image, alt: it.title || ('#' + it.id) } )
                                        ) : null )
                                    ),
                                    el( Button, { isSecondary: true, onClick: async () => { if ( it.content ) { insertContent( it.content ); } else { try { const det = await fetchPL( `/manager/patterns/${it.id}`, {}, { silent: true } ); if ( det && det.content ) insertContent( det.content ); } catch(e) {} } } }, __( 'Import', 'wpkj-patterns-library' ) )
                                ) )
                            ),
                            el( Button, { isTertiary: true, onClick: () => { setImportHistory( [] ); writeImportHistory( [] ); } }, __( 'Clear', 'wpkj-patterns-library' ) )
                        ) : null
                    ),
                    el( 'div', { className: 'wpkj-pl-main' },
                        el( 'div', { className: 'wpkj-pl-heading' }, __( 'All Patterns', 'wpkj-patterns-library' ) ),
                        error && el( 'div', { className: 'notice notice-error' }, error ),
                        el( 'div', { className: 'wpkj-pl-content' },
                            el( 'div', { className: 'wpkj-pl-grid', ref: gridRef },
                                ( () => {
                                    const displayItems = onlyFavorites ? items.filter( x => favorites.includes( x.id ) ) : items;
                                    const cardEls = [];
                                    // Render existing items first
                                    if ( displayItems && displayItems.length ) {
                                        displayItems.forEach( ( it ) => {
                                            const thumb = getThumbFromItem( it );
                                            const link = it.link || '#';
                                            const isFav = favorites.includes( it.id );
                                            const toggleFav = async () => {
                                                const next = isFav ? favorites.filter( id => id !== it.id ) : [ ...favorites, it.id ];
                                                setFavorites( next );
                                                writeFavorites( next );
                                                try {
                                                    const action = isFav ? 'remove' : 'add';
                                                    await fetchPL( '/favorites', { id: it.id, action }, { method: 'POST', silent: true } );
                                                } catch(e) {}
                                            };
                                            cardEls.push(
                                                el( 'div', { key: it.id, className: 'wpkj-pl-card', 'data-id': it.id },
                                                    el( 'div', { className: 'wpkj-pl-card-media' },
                                                        thumb ? el( 'img', { src: thumb, alt: it.title || ('#' + it.id), className: 'wpkj-pl-thumb', loading: 'lazy', onError: (e) => { e.currentTarget.style.visibility = 'hidden'; } } ) : el( 'div', { className: 'wpkj-pl-thumb' }, '' ),
                                                        el( 'div', { className: 'wpkj-pl-card-overlay' },
                                                            el( 'a', { href: link, target: '_blank', rel: 'noopener', className: 'components-button is-secondary' }, __( 'Preview', 'wpkj-patterns-library' ) ),
                                                            el( Button, { 
                                                                isPrimary: true, 
                                                                onClick: () => setConfirmImportPattern( it )
                                                            }, __( 'Import', 'wpkj-patterns-library' ) ),
                                                            el( Button, { className: 'wpkj-pl-fav-toggle' + ( isFav ? ' is-active' : '' ), isSecondary: true, onClick: toggleFav, 'aria-label': __( 'Favorite', 'wpkj-patterns-library' ), title: __( 'Favorite', 'wpkj-patterns-library' ) }, isFav ? '★' : '☆' )
                                                        )
                                                    ),
                                                    el( 'div', { className: 'wpkj-pl-card-title' }, it.title || ('#' + it.id) )
                                                )
                                            );
                                        } );
                                    }

                                    // If loading and appending, show skeletons after existing items
                                    if ( loading && ( isAppending && ( displayItems && displayItems.length ) ) ) {
                        const skelCount = skeletonCount || Math.min( PER_PAGE, 12 );
                                        for ( let i = 0; i < skelCount; i++ ) {
                                            cardEls.push(
                                                el( 'div', { key: 'skel-' + i + '-' + pageRef.current, className: 'wpkj-pl-card skeleton' },
                                                    el( 'div', { className: 'wpkj-pl-card-media' },
                                                        el( 'div', { className: 'wpkj-pl-thumb skel' } )
                                                    ),
                                                    el( 'div', { className: 'wpkj-pl-card-title skel' } )
                                                )
                                            );
                                        }
                                    }

                                    // If not appending and still loading with empty list (initial/search reset), show skeleton only
                                    if ( loading && ( ! isAppending ) && ( ! (displayItems && displayItems.length) ) ) {
                                        const count = skeletonCount || Math.min( PER_PAGE, 12 );
                                        return Array.from( { length: count } ).map( ( _, idx ) => el( 'div', { key: 'skel-init-' + idx, className: 'wpkj-pl-card skeleton' },
                                            el( 'div', { className: 'wpkj-pl-card-media' },
                                                el( 'div', { className: 'wpkj-pl-thumb skel' } )
                                            ),
                                            el( 'div', { className: 'wpkj-pl-card-title skel' } )
                                        ) );
                                    }

                                    if ( ! cardEls.length ) {
                                        return el( 'div', { className: 'wpkj-pl-empty' }, __( 'No results found.', 'wpkj-patterns-library' ) );
                                    }
                                    return cardEls;
                                } )()
                            )
                        ),
                        // Actions inside main: load more
                        el( 'div', { className: 'wpkj-pl-main-actions' },
                            el( Button, { isSecondary: true, disabled: loading || ! hasMore, onClick: () => load( false ) }, hasMore ? __( 'Load more', 'wpkj-patterns-library' ) : __( 'No more', 'wpkj-patterns-library' ) )
                        )
                    )
                ),
                // Removed duplicate outside main actions container
            )
        );
    };

    domReady( () => {
        // Capture last clicked block to remember insertion point even if focus is lost
        const captureFromEl = ( elTarget ) => {
            try {
                const blockEl = elTarget && elTarget.closest ? elTarget.closest( '.block-editor-block-list__block' ) : null;
                const cidAttr = blockEl && blockEl.dataset ? blockEl.dataset.block : undefined;
                if ( ! cidAttr ) return;
                const beSelect = wp.data && wp.data.select ? wp.data.select( 'core/block-editor' ) : null;
                if ( ! beSelect ) return;
                const rootCid = beSelect.getBlockRootClientId ? beSelect.getBlockRootClientId( cidAttr ) : undefined;
                let idx;
                if ( beSelect.getBlockIndex ) {
                    idx = beSelect.getBlockIndex( cidAttr, rootCid );
                }
                lastInsertionIndex = ( typeof idx === 'number' ? ( idx + 1 ) : undefined );
                lastInsertionRoot = rootCid;
            } catch(e) {}
        };
        const onMouseDown = (e) => captureFromEl( e.target );
        const onClick = (e) => captureFromEl( e.target );
        document.addEventListener( 'mousedown', onMouseDown, true );
        document.addEventListener( 'click', onClick, true );

        // Minimal implementation: directly insert a button into left toolbar once
        const HeaderTrigger = () => {
            const [ open, setOpen ] = wp.element.useState( false );
            return el( Fragment, null,
                el( wp.components.Button, {
                    className: 'components-button pat-library-button',
                    isPrimary: true,
                    onClick: (e) => {
                        e.preventDefault();
                        try {
                            const beSelect = wp.data && wp.data.select ? wp.data.select( 'core/block-editor' ) : null;
                            if ( beSelect ) {
                                let sel = beSelect.getSelectionStart ? beSelect.getSelectionStart() : null;
                                if ( ( ! sel || ! sel.clientId ) && beSelect.getSelectedBlockClientId ) {
                                    const cid = beSelect.getSelectedBlockClientId();
                                    if ( cid ) sel = { clientId: cid };
                                }
                                if ( sel && sel.clientId ) {
                                    const rootCid = beSelect.getBlockRootClientId ? beSelect.getBlockRootClientId( sel.clientId ) : undefined;
                                    if ( beSelect.getBlockIndex ) {
                                        const currentIndex = beSelect.getBlockIndex( sel.clientId, rootCid );
                                        lastInsertionIndex = ( typeof currentIndex === 'number' ? ( currentIndex + 1 ) : undefined );
                                        lastInsertionRoot = rootCid;
                                    } else {
                                        lastInsertionIndex = undefined;
                                        lastInsertionRoot = rootCid;
                                    }
                                } else {
                                    lastInsertionIndex = undefined;
                                    lastInsertionRoot = undefined;
                                }
                            }
                        } catch(err) { lastInsertionIndex = undefined; lastInsertionRoot = undefined; }
                        setOpen( true );
                    },
                },
                    el( 'span', { className: 'dashicons dashicons-layout', 'aria-hidden': true, style: { marginRight: '6px' } } ),
                    __( 'Patterns Library', 'wpkj-patterns-library' )
                ),
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