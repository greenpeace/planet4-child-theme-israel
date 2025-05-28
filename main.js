/* Main JS file
 * Built by DgCult - Eko Goren 3/2017
 * ekogoren@gmail.com
 */

(function($) {

    $(document).ready(function(){
        if($(".donation-fix-height").length){
            var screenHeight = $(window).height();
            var bottom = $(".donation-bottom").offset().top + $(".donation-bottom").height();
            var gap = screenHeight - bottom;
            if(gap) $(".donation-bottom  .elementor-widget-container").css("min-height",(($(".donation-bottom").height()+gap+20))+"px");
        }
    });

    var gpf_req = "שדה חובה";


    // Phone validation
    $("body").on( "keyup change focusout", "#gpf_form #gpf_phone",  function() {

        var input = Number($(this).val());
        if(gpf_required($(this))){
            var isNumber = (Number.isInteger(input) && input != 0);
            if(!isNumber) {
                gpf_showError($(this), "מספרים בלבד");
            }else if($(this).val().length !== 10){
                gpf_showError($(this), "יש להקליד מספר טלפון נייד תקין");
            }else{
                gpf_clear($(this));
            }
        }
    });

    $('#gpf_radios label').click(function(){
        $(this).siblings("input").prop('checked', true).trigger("change");
    });

    // Email validation
    $("body").on( "keyup change focusout", "#gpf_form #gpf_email",  function() {

        if(gpf_required($(this))) {
            var val = $(this).val();
            var re = /\S+@\S+\.\S+/;
            if (val) {
                if (re.test(val)) {
                    gpf_clear($(this));
                }else{
                    gpf_showError($(this), "כתובת המייל אינה תקינה");
                }
            }else{
                gpf_clear($(this));
            }
        }
    });

    // Name validation
    $("body").on( "keyup change focusout", "#gpf_form #gpf_first_name, #gpf_form #gpf_last_name",  function() {

        if(gpf_required($(this))) {
            if($(this).val().length < 2){
                gpf_showError($(this), "השם חייב להכיל שני תווים לפחות");
            }else{
                gpf_clear($(this));
            }
        }
    });

    // Sum validation
    $("body").on( "keyup change focusout focusin", "#gpf_form #gpf_other",  function() {

        $(this).val($(this).val().replace("₪", "").trim());
        if( $('[value="other"][name="gpf_sum_radio"]:checked').length){
            if(gpf_required($(this))) {
                var input = Number($(this).val());
                var isNumber = (Number.isInteger(input) && input != 0);
                if(!isNumber) {
                    gpf_showError($(this), "מספרים בלבד");
                }else if(input < Number(greenpeace_donation_object.minSum)){
                    gpf_showError($(this),"סכום מינימום לתרומה " + greenpeace_donation_object.minSum + " ש״ח");
                }else{
                    gpf_clear($(this));
                }
            }
        }else{
            gpf_clear($(this));
        }

    });

    $("body").on( "focusout", "#gpf_form #gpf_other",  function() {

        var input = Number($(this).val());
        var isNumber = (Number.isInteger(input) && input != 0);
        if(isNumber) {
            $(this).val( $(this).val()+ " ₪");
        }
    });

    // Radio buttons
    $("body").on( "change", "#gpf_form input[type='radio']",  function() {

        if( $('[name="gpf_sum_radio"]:checked').val() === "other"){
            $("#gpf_form #gpf_other").removeAttr("disabled");
            $("#gpf_form #gpf_other").focus();
        }else{
            $("#gpf_form #gpf_other").attr("disabled", "disabled").attr('value', '').trigger('keyup');
        }

    });

	// Ofer Or 4-Dec-2024 change start  ====================
	
	 // ID_number validation
    $("body").on( "keyup change focusout", "#gpf_form #gpf_id_number",  function() {
		var checkbox = document.getElementById('gpf_igulLetovaCheckbox');
//		console.log("Ofer Debug 1   - a" )

		if (checkbox.checked) {
//			console.log("Ofer Debug 2:")
			if(gpf_required($(this))) {
				var val = $(this).val();
				var re = /^\d{9}$/;
				if (val) {
					if (re.test(val)) {
						gpf_clear($(this));
					}else{
						gpf_showError($(this), "יש למלא 9 ספרות");
					}
				}else{
					gpf_clear($(this));
				}
			}
		}
    });
	
	$("body").on( "click", "#gpf_form #gpf_igulLetovaCheckbox",  function() {
		
		var checkbox = document.getElementById('gpf_igulLetovaCheckbox'); 
		var idNumberField = document.getElementById('gpf_id_number'); 

		var val = checkbox.checked;
 		if (val) {
			idNumberField.style.display =  'block';
		} else {
			idNumberField.value =  "";
			idNumberField.style.display =  'none';
			gpf_clear($(idNumberField));
		};
	});

	// Ofer Or 4-Dec-2024 end change end =======================




    $("#gpf_form").submit(function(e){

        var gpfFormErrors = false;

        e.preventDefault();

        $(this).find("input[type='text']").each(function(index, element){

            $(element).trigger("change");
            if($(element).parent().find("p").text() !== "") gpfFormErrors = true;

        }).promise().done(function(){
            if(!gpfFormErrors){
                var formArr =  $("#gpf_form").serializeArray();
                var formData = {};
                formArr.forEach(function(val, index){
                    formData[val.name] = val.value;
                    if (index === formArr.length - 1) {
                        $('svg#gpf_loader').show();
                        gpfAjax("client",formData)
                    }
                });
            }else{
                $(".on-error").first().siblings().focus();
                if($("#gpf_form #gpf_other").val()) $("#gpf_form #gpf_other").val($("#gpf_form #gpf_other").val().trim()+ " ₪");
            }

        });

    });

    function gpfAjax(type, formData){

        //TODO add UTM

        formData.action = "gpf_db"+type;
        formData.nonce = greenpeace_donation_object.ajax_nonce;
        formData.page_id = greenpeace_donation_object.id;
        formData.payment_type = greenpeace_donation_object.payment_type;

        var utm = $("#utm_info");
        formData.utm_campaign = utm.attr("data-campaign");
        formData.utm_source = utm.attr("data-source");
        formData.utm_medium = utm.attr("data-medium");
        formData.utm_content = utm.attr("data-content");
        formData.utm_term = utm.attr("data-term");

        //console.log(formData);
        $.ajax({
            type: "post",
            dataType: 'html',
            url: greenpeace_donation_object.ajaxurl,
            data: formData,
            success: function (data) {
                console.log(data);
                history.pushState(null, null, 'stage-2');

                // Google analytics state
                //ga('set', 'page', '/new-page');
                //ga('send', 'pageview');

                $("#gpf_form").hide();
                $("img.steps-img").attr("src", "https://joinus.greenpeace.org.il/wp-content/uploads/2018/05/stage2.jpg").attr("alt", "step 2");
                $("#grf_frame").fadeIn();
                $('.donation-bottom .elementor-widget-container').css("background-color", "white");

                $('svg#gpf_loader').hide();
                $("#gpf_form").replaceWith(data);
            },
            error: function (data) {
                console.log(data);
            }
        });

    }

    $(window).on("popstate", function () {
        // if the state is the page you expect, pull the name and load it.
        if (window.location.href.indexOf("stage-2") === -1 ) {
            location.reload();
        }
    });

    function gpf_required(field){

        if(!field.val()) gpf_showError(field, gpf_req);
        return field.val();

    }

    function gpf_clear(field){

        field.parent().find("p").text("").css("opacity", 0);
        field.removeClass("on-error").attr("aria-invalid", false);


    }


    function gpf_showError(field, error) {

        field.parent().find("p").text(error).css("opacity", 1);
        field.addClass("on-error").attr("aria-invalid", true);

    }



})( jQuery );
//[greenpeace_username]