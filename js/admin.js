jQuery(document).ready(function($) {
    // Place Lookup functionality
    $('.lookup-place').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var placeId = $('#grf_place_id').val();
        
        if (!placeId) {
            alert('Please enter a Place ID');
            return;
        }
        
        button.prop('disabled', true).text('Looking up...');
        
        $.ajax({
            url: grfAdmin.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'grf_lookup_place',
                nonce: grfAdmin.nonce,
                place_id: placeId
            },
            success: function(response) {
                if (response.success) {
                    $('#place_name_display').text('Current Place: ' + response.data.name);
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to lookup place. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false).text('Lookup Place');
            }
        });
    });

    // Fetch Reviews functionality
    $('.button-fetch-reviews').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var statusDiv = $('#fetch-status');
        
        console.log('Starting fetch request...');
        
        button.prop('disabled', true).text('Fetching...');
        statusDiv.html('<div class="notice notice-info"><p>Fetching reviews...</p></div>');
        
        $.ajax({
            url: grfAdmin.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'fetch_google_reviews',
                nonce: grfAdmin.nonce
            },
            beforeSend: function() {
                console.log('Sending AJAX request...');
            },
            success: function(response) {
                console.log('Response received:', response);
                if (response.success) {
                    statusDiv.html('<div class="notice notice-success"><p>' + escapeHtml(response.data) + '</p></div>');
                } else {
                    statusDiv.html('<div class="notice notice-error"><p>Error: ' + escapeHtml(response.data) + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('XHR:', xhr);
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response Text:', xhr.responseText);
                
                var errorMessage = error;
                try {
                    var responseJson = JSON.parse(xhr.responseText);
                    if (responseJson.data) {
                        errorMessage = responseJson.data;
                    }
                } catch(e) {}
                
                statusDiv.html('<div class="notice notice-error"><p>Server Error: ' + escapeHtml(errorMessage) + '</p></div>');
            },
            complete: function() {
                console.log('Request completed');
                button.prop('disabled', false).text('Fetch Reviews Now');
            }
        });
    });

    // Helper function to escape HTML
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}); 