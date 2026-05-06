$(document).ready(function() {
    // Auto-scroll to bottom of chat
    const chatMessages = $('#chatMessages');
    chatMessages.scrollTop(chatMessages[0].scrollHeight);

    // Character counter
    $('#message').on('input', function() {
        const length = $(this).val().length;
        $('#charCount').text(length + '/1000');
        if (length > 1000) {
            $('#charCount').addClass('text-danger');
        } else {
            $('#charCount').removeClass('text-danger');
        }
    });

    // Mention user
    $('.mention-user').click(function() {
        const username = $(this).data('username');
        const textarea = $('#message');
        const current = textarea.val();
        const cursorPos = textarea[0].selectionStart;
        const textBefore = current.substring(0, cursorPos);
        const textAfter = current.substring(cursorPos);
        const needsLeadingSpace = textBefore.length > 0 && !/\s$/.test(textBefore);
        const insertText = (needsLeadingSpace ? ' ' : '') + username + ' ';

        const newValue = textBefore + insertText + textAfter;
        textarea.val(newValue);
        textarea.focus();
        const newCursorPos = cursorPos + insertText.length;
        textarea[0].selectionStart = textarea[0].selectionEnd = newCursorPos;

        // Update character count
        $('#charCount').text(newValue.length + '/1000');
    });

    // Clear message
    $('#clearMessage').click(function() {
        $('#message').val('');
        $('#charCount').text('0/1000');
    });

    // Refresh chat
    $('#refreshChat').click(function() {
        location.reload();
    });

    // Auto-refresh every 30 seconds
    setInterval(function() {
        $.ajax({
            url: window.location.href,
            type: 'GET',
            success: function(data) {
                // Extract only the chat messages from the response
                const newMessages = $(data).find('#chatMessages').html();
                const currentScroll = chatMessages.scrollTop();
                const isAtBottom = currentScroll + chatMessages.innerHeight() >= chatMessages[0].scrollHeight - 50;

                chatMessages.html(newMessages);

                // Auto-scroll if user was at bottom
                if (isAtBottom) {
                    chatMessages.scrollTop(chatMessages[0].scrollHeight);
                }
            }
        });
    }, 30000);

    // Prevent form resubmission on refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
