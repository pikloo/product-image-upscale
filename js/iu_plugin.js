jQuery.noConflict();
jQuery(function ($) {
    const data = {
        'operations': {
            'restorations': {
                'upscale': 'smart_resize'
            },
            'resizing': {
                'width': iu_data.width,
                'height': iu_data.width,
                'fit': 'bounds'
            }
        },
        'output': {
            'format': {
                'type': iu_data.output_filetype == 0 ? 'jpeg' : iu_data.output_filetype,
                'quality': 80,
                'progressive': true
            }
        },
        'input': null
    };

    let treatedImagesNumber = 0;
    let replacedImagesNumber = 0;


    $('body').on('click', '[data-type-submit="upscale"]', function (e) {
        e.preventDefault()
        const button = $(e.target);

        if (!$('.iu-all-upscale-log').length) {
            const html = `<div class="iu-all-upscale-log">
            <h3>Logs</h3>
            <ul id="iu-all-upscale-log__list"></ul>
            </div>
            `
            const progressBar = `
            <div class="iu-all-upscale-progress"><div id="iu-all-upscale-progress__treated"><span id="iu-all-upscale-progress__treated__percent">0</span>%</div></div>
            <div id="iu-all-upscale-result"><span>0</span>image(s) redimensionnée(s)</div>
            `
            $('#wpbody-content').append(progressBar);
            $('#wpbody-content').append(html);
        }

        $('#iu-all-upscale-log__list').html('');

        $.ajax({
            url: iu_data.ajax_url,
            type: 'POST',
            data: {
                action: 'iu_get_all_products_images',
                security: iu_data.nonce,
            },
            beforeSend: function () {
                button.prop('disabled', true);
                button.text(iu_data.loading_message)
            },
            success: function (response) {
                const attachments = JSON.parse(response);
                attachments.length > 0 && attachments.forEach(function (attachment) {
                    const imgUrl = attachment.guid;
                    // replace_attachment(imgUrl, null, 'all'); // Pour tester sans appel à claid, décommenter cette ligne
                    call_claid_api(imgUrl, button, 'all') // Pour tester sans appel à claid, commenter cette ligne
                });
            }
        });
    })

    $('body').on('click', '[data-type-submit="upscale-item"]', function (e) {
        e.preventDefault();
        const button = $(e.target);
        const attachmentUrl = $(e.target).data('attachment-url');
        // replace_attachment(attachmentUrl, null, 'list', button); // Pour tester sans appel à claid, décommenter cette ligne
        call_claid_api(attachmentUrl, button) // Pour tester sans appel à claid, commenter cette ligne
    })

    /**
     * Appel AJAX à l'API CLAID
     * @param {string} imageSource 
     * @param {HTMLButtonElement} button 
     * @param {string} mode 
     */
    function call_claid_api(imageSource, button, mode = 'list') {
        data.input = imageSource
        const buttonOriginalText = button.text();
        $.ajax({
            url: iu_data.api_url,
            data: JSON.stringify(data),
            contentType: 'application/json; charset=utf-8',
            type: 'POST',
            dataType: 'json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('Authorization', 'Bearer ' + iu_data.bearer);
                button.prop('disabled', true);
                button.text(iu_data.loading_message)
            },
            success: function (response) {
                const data = response.data.output.tmp_url;
                const buttonToSend = mode === 'list' ? button : null;
                replace_attachment(imageSource, data, mode, buttonToSend);
                if (mode == 'all'){
                    button.prop('disabled', false);
                    button.text(buttonOriginalText)
                }
                
                
            },
            error: function (response) {
                if (mode == 'all') {
                    //Ajouter un log à la liste
                    const html = `<li class="iu-all-upscale-log__list__item">${imageSource}<span class="iu-all-upscale-log__list__item-status" data-status="error"><i class="fa-solid fa-circle-xmark"></i></span></li>`
                    $('#iu-all-upscale-log__list').append(html);

                }
                if (mode == 'list'){
                    const data = JSON.parse(response.responseText);
                    const attachmentId = button.data('attachment-id');
                    $(`.iu-list-error[data-attachment-id="${attachmentId}"]`).remove();
                    button.after(`<div class="iu-list-error" data-attachment-id="${attachmentId}" ><span class="iu-all-upscale-log__list__item-status" data-status="error"><i class="fa-solid fa-circle-xmark"></i></span> Erreur ${data.error_message}</div>`);
                }
                button.text(buttonOriginalText)
                button.prop('disabled', false);

            },
        })
    }

    /**
     * Appel AJAX à la méthode de remplacement d'une image
     * @param {string} imageSource 
     * @param {string} url 
     * @param {string} mode 
     * @param {HTMLButtonElement | null} button
     */
    function replace_attachment(imageSource, url , mode = 'list', button = null) {
        let totalAttachmentCount = 0
        if (mode === 'all') {
            totalAttachmentCount = document.querySelector('p[data-elements-to-treat-nb]').getAttribute('data-elements-to-treat-nb');
        }
        $.ajax({
            url: iu_data.ajax_url,
            type: 'POST',
            data: {
                action: 'iu_replace_attachment',
                security: iu_data.nonce,
                imageSource,
                newImage: url
            },
            success: function (response) {
                if (mode == 'all') {
                    const html = `<li class="iu-all-upscale-log__list__item">${imageSource}<span class="iu-all-upscale-log__list__item-status" data-status="success"><i class="fa-solid fa-circle-check"></i></span></li>`
                    $('#iu-all-upscale-log__list').append(html);
                    replacedImagesNumber++;
                    $('#iu-all-upscale-result span').text(replacedImagesNumber);
                    const textNbImagesToUpscale = $('iu-upscale-all-to-rescale-count');
                    textNbImagesToUpscale.text(Number(textNbImagesToUpscale.text()) - replacedImagesNumber);
                }

                if (mode == 'list'){
                    button.prop('disabled', true);
                    button.html(`
                        Done <span class="iu-all-upscale-log__list__item-status" data-status="success"><i class="fa-solid fa-circle-check"></i></span>
                        `)
                }
            },
            error: function (response) {
                if (mode == 'all') {
                    const html = `<li class="iu-all-upscale-log__list__item">${imageSource}<span class="iu-all-upscale-log__list__item-status" data-status="error"><i class="fa-solid fa-circle-xmark"></i></span></li>`
                    $('#iu-all-upscale-log__list').append(html);
                }
            },
            complete: function () {
                if (mode == 'all') {
                    treatedImagesNumber++;
                    let percentProgress = treatedImagesNumber * 100 / totalAttachmentCount;
                    $('#iu-all-upscale-progress__treated').width(`${percentProgress}%`);
                    $('#iu-all-upscale-progress__treated__percent').text(percentProgress.toFixed(0));

                    if (treatedImagesNumber === totalAttachmentCount - 1) {
                        if (replacedImagesNumber === 0) $('#iu-all-upscale-progress__treated').remove();
                    }
                }




            }
        });
    }
});