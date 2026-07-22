<?php
/**
 * Google Tag Manager compartilhado por todos os layouts PHP.
 */

function renderGoogleTagManagerHead(): void {
    ?>
<!-- Google Tag Manager -->
<script>
(function(w,d,s,l,i){
    w[l]=w[l]||[];
    w[l].push({
        'gtm.start': new Date().getTime(),
        event:'gtm.js'
    });
    var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),
        dl=l!='dataLayer'?'&l='+l:'';
    j.async=true;
    j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
    f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-PSG7TCVT');
</script>
<!-- End Google Tag Manager -->
<?php
}

function renderGoogleTagManagerBody(): void {
    ?>
<!-- Google Tag Manager (noscript) -->
<noscript>
    <iframe
        src="https://www.googletagmanager.com/ns.html?id=GTM-PSG7TCVT"
        height="0"
        width="0"
        style="display:none;visibility:hidden">
    </iframe>
</noscript>
<!-- End Google Tag Manager (noscript) -->
<?php
}
