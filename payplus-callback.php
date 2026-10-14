<?php
/*
 * Template Name: payplus-callback
 */

// PayPlus posts here after a payment (refURL_callback in getIframe). The work,
// and the checks that come before anything is saved, live in
// GreenDonationPayments; it also answers /payplus-callback/ from
// template_redirect, so this template is reached only if the page's slug
// changes while it keeps this template.
GreenDonationPayments::handleCallbackRequest();
