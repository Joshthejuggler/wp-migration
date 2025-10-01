(function () {
    if ( ! window.jmigrateAdmin ) {
        return;
    }

    const ajaxUrl = jmigrateAdmin.ajaxUrl;
    const nonce = jmigrateAdmin.nonce;
    const strings = jmigrateAdmin.strings || {};

    // Initialize tabs immediately
    console.log('JMigrate admin script loaded');
    
    // Try immediate execution first
    setTimeout(function() {
        console.log('Attempting immediate tab setup');
        setupTabs();
    }, 100);
    
    // Also try after DOM ready
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(function($) {
            console.log('jQuery ready, setting up tabs');
            setupTabs();
        });
    }
    
    // And try after window load
    window.addEventListener('load', function() {
        console.log('Window loaded, setting up tabs');
        setupTabs();
    });
    
    initializeTabs();

    const importForm = document.getElementById( 'jmigrate-import-form' );
    if ( ! importForm || ! window.FormData ) {
        return;
    }

    const runButton = importForm.querySelector( '[name="jmigrate_run_import"]' );
    const progressWrap = document.getElementById( 'jmigrate-progress' );
    const progressBar = document.getElementById( 'jmigrate-progress-bar' );
    const progressStatus = document.getElementById( 'jmigrate-progress-status' );
    const progressLog = document.getElementById( 'jmigrate-progress-log' );

    let pollTimer = null;
    let lastMessageCount = 0;

    importForm.addEventListener( 'submit', function ( event ) {
        event.preventDefault();

        const formData = new FormData( importForm );
        formData.append( 'action', 'jmigrate_start_import' );
        formData.append( '_ajax_nonce', nonce );

        resetProgress();
        showProgress( true );
        setProgress( 5, strings.starting || 'Starting import…' );

        disableButton( true );

        fetch( ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        } ).then( handleJsonResponse )
            .then( function ( response ) {
                if ( ! response.success ) {
                    handleError( response.data || strings.genericError || 'Import failed to start.' );
                    return;
                }

                const jobId = response.data.job_id;
                triggerJob( jobId );
                pollStatus( jobId );
            } )
            .catch( function ( error ) {
                handleError( error.message );
            } );
    } );

    function triggerJob( jobId ) {
        const body = new URLSearchParams( {
            action: 'jmigrate_run_import_job',
            job_id: jobId,
            _ajax_nonce: jmigrateAdmin.runNonce,
        } );

        fetch( ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body,
        } ).then( handleJsonResponse )
            .then( function ( response ) {
                if ( ! response.success ) {
                    handleError( response.data || strings.genericError || 'Import failed.' );
                }
            } )
            .catch( function ( error ) {
                handleError( error.message );
            } );
    }

    function handleJsonResponse( response ) {
        if ( ! response.ok ) {
            throw new Error( strings.genericError || 'The request failed.' );
        }

        return response.json();
    }

    function pollStatus( jobId ) {
        if ( ! jobId ) {
            handleError( strings.genericError || 'Missing job identifier.' );
            return;
        }

        const params = new URLSearchParams( {
            action: 'jmigrate_get_import_status',
            job_id: jobId,
            _ajax_nonce: nonce,
        } );

        fetch( ajaxUrl + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
        } ).then( handleJsonResponse )
            .then( function ( response ) {
                if ( ! response.success ) {
                    handleError( response.data || strings.genericError || 'Import failed.' );
                    return;
                }

                const data = response.data || {};
                updateProgressUI( data );

                if ( 'running' === data.status ) {
                    pollTimer = setTimeout( function () {
                        pollStatus( jobId );
                    }, 2000 );
                } else {
                    disableButton( false );
                    if ( 'success' === data.status ) {
                        setProgress( 100, strings.completed || 'Import completed.' );
                    }
                }
            } )
            .catch( function ( error ) {
                handleError( error.message );
            } );
    }

    function updateProgressUI( data ) {
        if ( typeof data.progress === 'number' ) {
            setProgress( data.progress, data.status_label || '' );
        }

        const messages = data.messages || [];
        if ( messages.length > lastMessageCount ) {
            for ( let i = lastMessageCount; i < messages.length; i++ ) {
                appendLog( messages[ i ] );
            }
            lastMessageCount = messages.length;
        }

        if ( data.status ) {
            switch ( data.status ) {
                case 'running':
                    setProgress( data.progress || 10, strings.running || 'Import in progress…' );
                    break;
                case 'success':
                    appendLog( { type: 'success', message: strings.completed || 'Import completed.' } );
                    break;
                case 'error':
                    appendLog( { type: 'error', message: data.error || strings.genericError || 'Import failed.' } );
                    break;
            }
        }
    }

    function setProgress( percent, label ) {
        if ( progressBar ) {
            progressBar.style.width = Math.max( 0, Math.min( 100, percent ) ) + '%';
        }
        if ( label && progressStatus ) {
            progressStatus.textContent = label;
        }
    }

    function appendLog( entry ) {
        if ( ! progressLog ) {
            return;
        }
        const li = document.createElement( 'li' );
        li.textContent = entry.message || '';
        li.classList.add( entry.type || 'info' );
        progressLog.appendChild( li );
        progressLog.scrollTop = progressLog.scrollHeight;
    }

    function resetProgress() {
        lastMessageCount = 0;
        if ( progressBar ) {
            progressBar.style.width = '0%';
        }
        if ( progressStatus ) {
            progressStatus.textContent = '';
        }
        if ( progressLog ) {
            progressLog.innerHTML = '';
        }
        if ( pollTimer ) {
            clearTimeout( pollTimer );
            pollTimer = null;
        }
    }

    function showProgress( show ) {
        if ( ! progressWrap ) {
            return;
        }
        if ( show ) {
            progressWrap.removeAttribute( 'hidden' );
        } else {
            progressWrap.setAttribute( 'hidden', 'hidden' );
        }
        progressWrap.classList.toggle( 'is-visible', !! show );
    }

    function handleError( message ) {
        disableButton( false );
        showProgress( true );
        setProgress( 100, strings.failed || 'Import failed.' );
        appendLog( { type: 'error', message: message || strings.genericError || 'Import failed.' } );
    }

    function disableButton( disabled ) {
        if ( runButton ) {
            runButton.disabled = !! disabled;
        }
    }

    function setupTabs() {
        console.log('setupTabs called');
        
        // Use vanilla JS first, then try jQuery if available
        var tabButtons = document.querySelectorAll('.jmigrate-tab');
        var tabContents = document.querySelectorAll('.jmigrate-tab-content');
        
        console.log('Found tab buttons:', tabButtons.length);
        console.log('Found tab contents:', tabContents.length);
        
        if (tabButtons.length === 0 || tabContents.length === 0) {
            console.log('No tabs found, retrying...');
            return false;
        }
        
        // Hide all content first
        tabContents.forEach(function(content) {
            content.classList.remove('active');
            content.style.display = 'none';
        });
        
        // Show export tab by default
        var exportTab = document.getElementById('jmigrate-export');
        if (exportTab) {
            exportTab.classList.add('active');
            exportTab.style.display = 'block';
            console.log('Export tab shown by default');
        }
        
        // Add click handlers
        tabButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Tab clicked:', button.dataset.tab);
                
                var target = button.dataset.tab;
                
                // Remove active from all tabs
                tabButtons.forEach(function(btn) {
                    btn.classList.remove('nav-tab-active');
                });
                
                // Hide all content
                tabContents.forEach(function(content) {
                    content.classList.remove('active');
                    content.style.display = 'none';
                });
                
                // Activate clicked tab
                button.classList.add('nav-tab-active');
                
                // Show corresponding content
                var targetContent = document.getElementById('jmigrate-' + target);
                if (targetContent) {
                    targetContent.classList.add('active');
                    targetContent.style.display = 'block';
                    console.log('Showing content for:', target);
                }
            });
        });
        
        console.log('Tab setup complete');
        return true;
    }
    
    function initializeTabs() {
        // Use jQuery for tab functionality
        jQuery(document).ready(function($) {
            console.log('Tab initialization starting');
            console.log('Tab content elements found:', $('.jmigrate-tab-content').length);
            console.log('Tab elements found:', $('.jmigrate-tab').length);
            
            // Initialize tabs - show export tab by default
            $('.jmigrate-tab-content').removeClass('active');
            $('#jmigrate-export').addClass('active');
            
            // Handle tab clicks
            $('.jmigrate-tab').on('click', function(e) {
                e.preventDefault();
                
                var target = $(this).data('tab');
                
                // Remove active class from all tabs and nav-tab-active
                $('.jmigrate-tab').removeClass('nav-tab-active');
                
                // Hide all tab content
                $('.jmigrate-tab-content').removeClass('active');
                
                // Add active class to clicked tab
                $(this).addClass('nav-tab-active');
                
                // Show corresponding section
                $('#jmigrate-' + target).addClass('active');
            });
        });
    }
})();
