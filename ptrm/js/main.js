(function () {
  "use strict";

  const contentsToCheck = {};

  document.addEventListener("DOMContentLoaded", function(event) {
    const ptrmContainers = document.getElementsByClassName("ptrm-container");

    for( let i=0; i<ptrmContainers.length; i++ ) {
      const ptrmContainer = ptrmContainers[i];
      const content_id = ptrmContainer.id.split( '-' )[1];
      ptrm( ptrmContainer, content_id, ptrm_uiConfigTemplate );
    }

    setInterval( update, 3000 );
  });

  function ptrm( parentDiv, content_id, uiConfig ) {

    const payToReadMoreButton = document.createElement( 'button' );
    parentDiv.appendChild( payToReadMoreButton );
    payToReadMoreButton.innerHTML = uiConfig.lightbox_showptrmtext;
    payToReadMoreButton.style = uiConfig.lightbox_showptrmcss;

    registerContentForChecking( content_id );

    payToReadMoreButton.addEventListener("click", function() {

      requestInvoice( content_id, function( paid, invoice ) {

        if( paid ) {
          // somehow invoice was already paid, reload page to display content.
          window.location.reload();
          return;
        }

        document.getElementById("blackBackground2").style.display = "block";
        document.getElementById("lightbox").innerHTML = `
<div id="lightbox_close_button" onclick="lightboxGone()" style="${uiConfig.lightbox_closecss2}">&times;</div>
<p id="lightbox_top_text" style="text-align: center;">${uiConfig.lightbox_toptext2}</p>
`;
        document.getElementById("lightbox").appendChild( createQR2( invoice.toUpperCase() ) );
        document.getElementById("lightbox").innerHTML += `
<div align="center">
  <div id="invoiceDescriptor" style="${uiConfig.lightbox_descriptorcss}">${uiConfig.lightbox_invoicedescriptor}</div>
  <div>
    <input style="${uiConfig.lightbox_invoicebox2}" id="invoiceBox2" value="${invoice}" />
    <div id="copyButton2" style="${uiConfig.lightbox_copycss2}">&#x1F5CD;</div>
  </div>
  <br>
  <a href="lightning:${invoice}" target="_blank"><button type="button">${uiConfig.lightbox_btntext2}</button></a>
  <br>
  <br>
  <p>${uiConfig.lightbox_bottomtext2}</p>
</div>
`;
        setLightboxPosition();
        document.getElementById("copyButton2").addEventListener("click", function () {
          document.getElementById("invoiceBox2").select();
          document.getElementById("invoiceBox2").setSelectionRange(0, 99999)
          document.execCommand("copy");
        });
      });
    });
  }

  function update() {
    for( const contentId in contentsToCheck ) {
      // asking backend.
      checkContentIsPaidFor( contentId, function ( paid, contentId ) {
        if( paid && contentsToCheck[contentId] ) {
          // we are waiting for payment.
          window.location.reload();
        }
      });
    }
  }

  function registerContentForChecking( contentId ) {
    if( !contentsToCheck[contentId] ) {
      // maybe timeout later?
      contentsToCheck[contentId]=(new Date()).getTime();
    }
  }

  function requestInvoice( contentId, cb ) {

    if( !cb || !contentId ) {
      return;
    }

    const http = new XMLHttpRequest();
    const url = "/?rest_route="+encodeURIComponent("/ptrm/v1/invoice/"+contentId);

    http.open("GET", url, true);
    //http.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    http.onreadystatechange = function() {
      if(http.readyState === 4 && http.status === 200) {
        try {
          const body = JSON.parse(http.responseText);
          cb( !!body.paid, body.invoice, contentId );
        } catch( e ) {
          console.log( "error parsing response for /ptrm/v1/invoice/");
          cb( false, contentId )
        }
      }
    }
    http.send();

  }

  function checkContentIsPaidFor( contentId, cb ) {

    if( !cb || !contentId ) {
      return;
    }

    const http = new XMLHttpRequest();
    const url = "/?rest_route="+encodeURIComponent("/ptrm/v1/paid/"+contentId);

    http.open("GET", url, true);
    //http.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    http.onreadystatechange = function() {
      if(http.readyState === 4 && http.status === 200) {
        try {
          const body = JSON.parse(http.responseText);
          cb( body.paid, contentId );
        } catch( e ) {
          console.log( "error parsing response for /ptrm/v1/paid/");
          cb( false, contentId )
        }
      }
    }
    http.send();
  }

  function createQR2( invoice ) {
    const dataUriPngImage = document.createElement( "img" );
    dataUriPngImage.src = QRCode.generatePNG( invoice, {
      ecclevel: "M",
      format: "html",
      fillcolor: "#FFFFFF",
      textcolor: "#373737",
      margin: 4,
      modulesize: 8
    } );
    dataUriPngImage.id = "invoice_qr_code";
    dataUriPngImage.style.display = "block";
    dataUriPngImage.style.margin = "auto";
    return dataUriPngImage;
  }

})();
