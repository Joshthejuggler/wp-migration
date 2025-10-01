(function () {
    if ( ! window.jmigrateAdmin ) {
        return;
    }

    const ajaxUrl = jmigrateAdmin.ajaxUrl;
    const nonce = jmigrateAdmin.nonce;
    const strings = jmigrateAdmin.strings || {};

    console.log('JMigrate admin script loaded');

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

})();
