<?php

/**
* Plugin Name: PTRM - Pay to read more
* Plugin URI: https://github.com/schulterklopfer/ptrm
* Description: Pay over lightning for wordpress content
* Version: 0.1.0
* Author: SKP
* License: DON'T BE A DICK PUBLIC LICENSE
*/

// based on the btcpaywall plugin, but ended up a total rewrite.

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

global $ptrm_db_version;
global $create_pmt_table_query;
global $create_contentid_table_query;
global $wpdb;
global $pmt_table_name;
global $contentid_table_name;
global $ptrmid;
global $expiryInMinutes;

$expiryInMinutes = 120;
$ptrm_db_version = '0.1.0';
$pmt_table_name = $wpdb->prefix . 'ptrm';
$contentid_table_name = $wpdb->prefix . 'ptrm_contentid';

$charset_collate = $wpdb->get_charset_collate();

$create_pmt_table_query = "CREATE TABLE $pmt_table_name (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  wp_users_id bigint(20) default -1,
  ext_id varchar(23),
  content_id varchar(32) NOT NULL,
  pmt_hash varchar(64) NOT NULL,
  invoice text NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  was_paid BOOLEAN DEFAULT false,
  PRIMARY KEY (id),
  UNIQUE KEY ".$pmt_table_name."_pmt_hash_key (pmt_hash),
  KEY ".$pmt_table_name."_wp_users_id_key ( wp_users_id ),
  KEY ".$pmt_table_name."_wp_ext_id_key ( ext_id )
) $charset_collate;";

$create_contentid_table_query = "CREATE TABLE $contentid_table_name (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  content_id varchar(32) NOT NULL,
  unique_identifier text NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  modified_at TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ".$pmt_table_name."_content_id_key (content_id)
) $charset_collate;";

$select_payment_info_by_user_id_or_ext_id_query = "SELECT id, pmt_hash, invoice, was_paid FROM `$pmt_table_name` WHERE (expires_at > now() OR was_paid) AND  ( wp_users_id=%d OR ext_id=%s )";
$insert_payment_info_query  = "INSERT INTO `$pmt_table_name` (wp_users_id, ext_id, content_id, pmt_hash, invoice, expires_at) VALUES ( %d, %s, %s, %s, %s, ADDDATE(now(), INTERVAL %d MINUTE) )";
$update_payment_info_query  = "UPDATE `$pmt_table_name` SET pmt_hash=%s, invoice=%s, expires_at=ADDDATE(now(), INTERVAL %d MINUTE), was_paid=false WHERE id=%d";
$mark_payment_as_paid_query = "UPDATE `$pmt_table_name` SET was_paid=true WHERE pmt_hash=%s";
$select_userid_extid_contentid_by_pmthash_query = "SELECT wp_users_id, ext_id, content_id FROM `$pmt_table_name` WHERE pmt_hash=%d";
$delete_unpaid_payments_for_content_and_user_query = "DELETE FROM `$pmt_table_name` WHERE was_paid=false AND content_id=%s AND ( wp_users_id=%d OR ext_id=%s )";
$select_payment_info_by_content_and_user_id_or_ext_id_query = "SELECT id, pmt_hash, invoice, was_paid, expires_at < now() as is_expired FROM `$pmt_table_name` WHERE content_id=%s AND ( wp_users_id=%d OR ext_id=%s )";
$select_unpaid_pmthashes = "SELECT id, pmt_hash FROM `$pmt_table_name` WHERE NOT was_paid";


$insert_contentid_query = "INSERT INTO `$contentid_table_name` (content_id, unique_identifier, price) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE modified_at=now(), price=%d";
$select_content_query = "SELECT content_id, price FROM `$contentid_table_name` WHERE content_id=%s";

function ptrm_install() {
	global $ptrm_db_version;
  global $create_pmt_table_query;
  global $create_contentid_table_query;

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	dbDelta( array(
	  $create_pmt_table_query,
	  $create_contentid_table_query
	) );

	add_option( 'ptrm_db_version', $ptrm_db_version );
}

register_activation_hook( __FILE__, 'ptrm_install' );

function ptrm_update_db_check() {
  global $ptrm_db_version;
  if ( get_site_option( 'ptrm_db_version' ) != $ptrm_db_version ) {
    ptrm_install();
  }
}

add_action( 'plugins_loaded', 'ptrm_update_db_check' );

// add 5 seconds interval to cron schedules
add_filter( 'cron_schedules', 'ptrm_addCronIntervals' );

function ptrm_addCronIntervals( $schedules ) {
   $schedules['5seconds'] = array( // Provide the programmatic name to be used in code
      'interval' => 5, // Intervals are listed in seconds
      'display' => __('Every 5 Seconds') // Easy to read display name
   );
   return $schedules; // Do not forget to give back the list of schedules!
}

add_action( 'ptrm_checkInvoicesInLnbits_hook', 'cron_checkInvoicesInLnbits' );

if( !wp_next_scheduled( 'ptrm_checkInvoicesInLnbits_hook' ) ) {
  error_log( "scheduleing cron");
  wp_schedule_event( time(), '5seconds', 'ptrm_checkInvoicesInLnbits_hook' );
}

function cron_checkInvoicesInLnbits() {
  $unpaidPaymentHashes = getUnpaidPaymentHashes();

  if( count($unpaidPaymentHashes) == 0 ) {
    return;
  }

  foreach ($unpaidPaymentHashes as $unpaidPaymentHash) {
    $resp = wp_remote_get(
      get_option( 'lnbits_url ') . '/api/v1/payments/' . $unpaidPaymentHash->pmt_hash,
      array(
        'headers' => array(
          'X-Api-Key' => get_option( 'lnbits_api_key' )
        )
      )
    );
    $body = json_decode( $resp["body"] );

    if( $body->paid ) {
      markPaymentAsPaid( $unpaidPaymentHash->pmt_hash );
      deleteObsoletePayments( $unpaidPaymentHash->pmt_hash );
    }

  }

}

// http://localhost/?rest_route=/ptrm/v1/paid
function setup_rest() {
  register_rest_route( 'ptrm/v1', '/paid', array(
    'methods' => 'POST',
    'callback' => 'rest_markInvoiceAsPaid',
    'args' => array(),
    'permission_callback' => '__return_true'
  ) );
  register_rest_route( 'ptrm/v1', '/paid/(?P<content_id>[a-zA-Z0-9\._-]+)', array(
    'methods' => 'GET',
    'callback' => 'rest_contentIsPaidFor',
    'args' => array([
      'content_id'
    ]),
    'permission_callback' => '__return_true'
  ) );
  register_rest_route( 'ptrm/v1', '/invoice/(?P<content_id>[a-zA-Z0-9\._-]+)', array(
    'methods' => 'GET',
    'callback' => 'rest_invoiceForContent',
    'args' => array([
      'content_id'
    ]),
    'permission_callback' => '__return_true'
  ) );
}

function rest_markInvoiceAsPaid(WP_REST_Request $request) {
  $params = $request->get_json_params();

  // get payment hash from $params and mark it as paid.
  // the rest should be resolved by the client querying the paid/<content_id>
  // end point till it returns { "paid": true }

  error_log( json_encode( $params));

  if( !isset( $params['payment_hash'] ) ) {
    return new WP_REST_Response( array(), 404 );
  }
  $pmt_hash = $params['payment_hash'];
  markPaymentAsPaid( $pmt_hash );
  deleteObsoletePayments( $pmt_hash );
  return new WP_REST_Response( $params, 200 );
}

function rest_contentIsPaidFor(WP_REST_Request $request) {
  $ptrmid;
  $user_id = get_current_user_id();

  $url_params = $request->get_url_params();

  if ( !isset( $url_params['content_id'] ) ) {
    return new WP_REST_Response( array( 'paid' => false ), 200 );
  }

  $content_id = $url_params['content_id'];

  $content = getContent( $content_id );

  if( !$content ) {
    return new WP_REST_Response( array(), 404 );
  }

  if ( isset( $_COOKIE['wp-ptrmid'] ) ) {
    $ptrmid = $_COOKIE['wp-ptrmid'];
  }

  $infosForContent = getPaymentInfosByContentIdAndUserIdOrExtId( $content_id, $user_id, $ptrmid );

  $paidInfosForContent = array_filter($infosForContent, function($info, $index){
    return $info->was_paid;
  }, ARRAY_FILTER_USE_BOTH);

  if ( count( $paidInfosForContent ) > 0 ) {
    return new WP_REST_Response( array( 'paid' => true ), 200 );
  }

  return new WP_REST_Response( array( 'paid' => false ), 200 );
}

function rest_invoiceForContent(WP_REST_Request $request) {
  global $expiryInMinutes;

  $ptrmid;
  $user_id = get_current_user_id();
  $invoice;
  $pmt_hash;

  $url_params = $request->get_url_params();

  if ( !isset( $url_params['content_id'] ) ) {
    return new WP_REST_Response( array(), 404 );
  }

  $content_id = $url_params['content_id'];

  $content = getContent( $content_id );

  if( !$content ) {
    return new WP_REST_Response( array(), 404 );
  }

  $price = $content->price;

  if ( isset( $_COOKIE['wp-ptrmid'] ) ) {
    $ptrmid = $_COOKIE['wp-ptrmid'];
  }

  if( !$ptrmid && $user_id == 0 ) {
    return new WP_REST_Response( array(), 404 );
  }


  $infosForContent = getPaymentInfosByContentIdAndUserIdOrExtId( $content_id, $user_id, $ptrmid );

  $paidInfosForContent = array_filter($infosForContent, function($info, $index){
    return $info->was_paid;
  }, ARRAY_FILTER_USE_BOTH);

  if ( count($paidInfosForContent) > 0 ) {
    return new WP_REST_Response( array( 'paid' => true ), 200 );
  }

  $unpaidNotExpiredInfosForContent = array_filter($infosForContent, function($info, $index){
    return !$info->was_paid && !$info->is_expired;
  }, ARRAY_FILTER_USE_BOTH);

  if( count( $unpaidNotExpiredInfosForContent ) > 0 )  {
    $pmt_hash = $unpaidNotExpiredInfosForContent[0]->pmt_hash;
    $invoice = $unpaidNotExpiredInfosForContent[0]->invoice;
  } else {
    $amount_in_sats = priceToSats( $price, get_option( 'currency' ) );
    $data = requestInvoiceFromLnbits( $amount_in_sats, $content_id );
    $pmt_hash = $data['payment_hash'];
    $invoice = $data['payment_request'];
    insertPaymentInfo( $user_id==0?-1:$user_id, $ptrmid?$ptrmid:'%UNKNOWN%', $content_id, $pmt_hash, $invoice, $expiryInMinutes );
  }

  return new WP_REST_Response( array(
    'paid' => false,
    'pmt_hash' => $pmt_hash,
    'invoice' => $invoice
  ), 200 );
}


add_action( 'rest_api_init', 'setup_rest' );

function getContent( $content_id ) {
  global $select_content_query;
  global $wpdb;
  return $wpdb->get_row(
    $wpdb->prepare($select_content_query, array( $content_id ) )
  );
}

function getPaymentInfosByUserIdOrExtId( $user_id, $ext_id ) {
  global $select_payment_info_by_user_id_or_ext_id_query;
  global $wpdb;
  return $wpdb->get_results(
    $wpdb->prepare($select_payment_info_by_user_id_query, array( $user_id, $ext_id ) )
  );
}

function getPaymentInfosByContentIdAndUserIdOrExtId( $content_id, $user_id, $ext_id ) {
  global $select_payment_info_by_content_and_user_id_or_ext_id_query;
  global $wpdb;
  return $wpdb->get_results(
    $wpdb->prepare($select_payment_info_by_content_and_user_id_or_ext_id_query, array( $content_id, $user_id, $ext_id ) )
  );
}

function getUnpaidPaymentHashes() {
  global $select_unpaid_pmthashes;
  global $wpdb;
  return $wpdb->get_results($select_unpaid_pmthashes);
}

function insertPaymentInfo( $user_id, $ext_id, $content_id, $pmthash, $invoice, $expiryInMinutes ) {
  global $insert_payment_info_query;
  global $wpdb;
  $wpdb->query(
    $wpdb->prepare($insert_payment_info_query, array( $user_id, $ext_id, $content_id, $pmthash, $invoice, $expiryInMinutes ) )
  );
}

function insertContentId( $content_id, $unique, $price ) {
  global $insert_contentid_query;
  global $wpdb;
  $wpdb->query(
    $wpdb->prepare($insert_contentid_query, array( $content_id, $unique, $price, $price ) )
  );
}

function updatePaymentInfo(  $id, $pmthash, $invoice, $expiryInMinutes ) {
  global $update_payment_info_query;
  global $wpdb;

  $wpdb->query(
    $wpdb->prepare($update_payment_info_query, array( $pmthash, $invoice, $expiryInMinutes, $id ) )
  );

}

function markPaymentAsPaid( $pmthash ) {
  global $mark_payment_as_paid_query;
  global $wpdb;

  $wpdb->query(
    $wpdb->prepare($mark_payment_as_paid_query, array( $pmthash ) )
  );

}

function deleteObsoletePayments( $pmt_hash ) {
  global $select_userid_extid_contentid_by_pmthash_query;
  global $delete_unpaid_payments_for_content_and_user_query;
  global $wpdb;

  $row = $wpdb->get_row(
    $wpdb->prepare($select_userid_extid_contentid_by_pmthash_query, array( $pmt_hash ) )
  );

  if ( !$row ) {
    return;
  }

  $wpdb->query(
    $wpdb->prepare($delete_unpaid_payments_for_content_and_user_query, array( $row->content_id, $row->wp_users_id, $row->ext_id ) )
  );

}


function priceToSats( $price, $currency ) {
  if ( $currency == "usd" ) {
    // TODO: find better way to get spot price. Fuck Conbase!
    $resp = wp_remote_get( "https://api.coinbase.com/v2/prices/BTC-USD/spot" );
    $body = json_decode( $resp["body"] );
    $price_per_btc = $body->data->amount;
    return intval(ceil( $price * 100000000 / $price_per_btc ));
  }
  return intval($price);
}

function requestInvoiceFromLnbits( $amount, $memo ) {
  $url = get_option( 'lnbits_url ') . '/api/v1/payments';
  $resp = wp_remote_post( $url, array(
    'headers' => array(
      'Content-Type' => 'application/json',
      'X-Api-Key' => get_option( 'lnbits_api_key' )
    ),
    'body' => json_encode(array(
      'out' => false,
      'amount' => $amount,
      'memo' => $memo,
      'webhhook' => get_site_url() . '/?rest_route=/ptrm/v1/paid',
    ), JSON_NUMERIC_CHECK),
    'data_format' => 'body'
  ));

  $body = json_decode( $resp["body"], true );
  return $body;
}

function setPaywallIdCookie(){
  global $ptrmid;
  if ( isset( $_COOKIE['wp-ptrmid'] ) ) {
    $ptrmid = $_COOKIE['wp-ptrmid'];
  } else {
    $ptrmid = uniqid( "", true );
    setcookie('wp-ptrmid', $ptrmid, 2147483647, '/'  );
  }
}

add_action('init', 'setPaywallIdCookie');

function injectJSLibraries() {
  echo '
    <script src="/wp-content/plugins/ptrm/js/vendor/qr.js"></script>
    <script src="/wp-content/plugins/ptrm/js/main.js"></script>
    <script>
      const ptrm_uiConfigTemplate = {
        lightbox_closecss2: "'. get_option('lightbox_closecss2') . '",
        lightbox_toptext2: "'. get_option('lightbox_toptext2') . '",
        lightbox_descriptorcss: "'. get_option('lightbox_descriptorcss') . '",
        lightbox_invoicedescriptor: "'. get_option('lightbox_invoicedescriptor') . '",
        lightbox_invoicebox2: "'. get_option('lightbox_invoicebox2') . '",
        lightbox_copycss2: "'. get_option('lightbox_copycss2') . '",
        lightbox_btntext2: "'. get_option('lightbox_btntext2') . '",
        lightbox_bottomtext2: "'. get_option('lightbox_bottomtext2') . '",
        lightbox_showptrmcss: "'. get_option('lightbox_showptrmcss') . '",
        lightbox_showptrmtext: "'. get_option('lightbox_showptrmtext') . '"
      }
    </script>
  ';
}

add_action('wp_head', 'injectJSLibraries');

function ptrm($atts = array(), $content) {

  if( !isset( $atts["unique"] ) ) {
    return
      '<div style="color: #f00; font-weight: bold; border: 3px solid #f00; padding: 20px; ">please set a unique id for this ptrm shorthand using the "unique" attribute. ' .
      'Do not change this identifier later, cause if it changes, people will have to pay ' .
      'again for the same content.</div>';
  }

  if( !isset( $atts["price"] ) ) {
    return '<div style="color: #f00; font-weight: bold; border: 3px solid #f00; padding: 20px; ">please provide a price for this ptrm shorthand using the "price" attribute.</div>';
  }

  $price = floatval( $atts["price"] );

  // check for ptrm identifier
  global $ptrmid;
  $user_id = get_current_user_id();

  // hash content id to remove strange characters and shorten it a bit
  // for db storage it does not matter
  $content_id = hash('md5', $atts["unique"] );

  // insert uinque id of this shorthand into the database
  // cause we only create invoices for content ids present
  // in the db.
  insertContentId( $content_id, $atts["unique"], $price );

  $infosForContent = getPaymentInfosByContentIdAndUserIdOrExtId( $content_id, $user_id, $ptrmid );

  $paidInfosForContent = array_filter($infosForContent, function($info, $index){
    return $info->was_paid;
  }, ARRAY_FILTER_USE_BOTH);

	if( count($paidInfosForContent) > 0 ) {
	  return $content;
	} else {
    return '<div id="ptrm-' .$content_id. '" class="ptrm-container"></div>';
  }
}

add_shortcode("ptrm", "ptrm");

// *** utils down here *** //
function lightbox2() {

  if ( !empty( get_option( 'lightbox_blackbgroundcss2' ) ) ) {
    $blackbgroundcss = 'style="' . get_option( 'lightbox_blackbgroundcss2' ) . '"';
  } else {
    $blackbgroundcss = '';
  }
  if ( !empty( get_option( 'lightbox_boxcss2' ) ) ) {
    $boxcss = 'style="' . get_option( 'lightbox_boxcss2' ) . '"';
  } else {
    $boxcss = '';
  }

  echo '
  <div id="blackBackground2" ' . $blackbgroundcss . '></div>
  <div id="lightbox" ' . $boxcss . '></div>
  <script>

		function lightboxGone() {
      document.getElementById("blackBackground2").style.display = "none";
      document.getElementById("lightbox").style.display = "none";
    }

		document.getElementById("blackBackground2").onclick = function() {
			lightboxGone();
		}


    function setLightboxPosition() {
      document.getElementById("lightbox").style.display = "block";
      document.getElementById("lightbox").style.top = window.innerHeight/2 - document.getElementById("lightbox").offsetHeight/2 + "px";
      document.getElementById("lightbox").style.left = window.innerWidth/2 - document.getElementById("lightbox").offsetWidth/2 + "px";
    }

		document.getElementById("blackBackground2").style.height = document.body.offsetHeight + "px";

  </script>';
}

add_action( 'wp_footer', 'lightbox2' );

function ptrm_register_settings() {
	add_option( 'lnbits_url', '' );
	add_option( 'lnbits_api_key', '' );
	add_option( 'currency', 'sats' );
	add_option( 'lightbox_showptrmtext', 'Pay to read more...' );
	add_option( 'lightbox_showptrmcss', 'max-width: 400px; padding: 5px; font-size: 50%' );
	add_option( 'lightbox_boxcss2', 'background-color: white; color: black; position: fixed; padding: 20px; width: 80%; max-width: 400px; height: 100%; max-height: 590px; overflow-y: auto; border-radius: 20px; z-index: 2; display: none; z-index: 1;' );
	add_option( 'lightbox_blackbgroundcss2', 'width: 100%; position: fixed; top: 0px; left: 0px; background-color: black; z-index: 1; opacity: 0.7; display: none;' );
	add_option( 'lightbox_closecss2', 'float: right; font-size: 25px; line-height: 22px; cursor: pointer;' );
	add_option( 'lightbox_toptext2', 'Pay this invoice' );
	add_option( 'lightbox_invoicedescriptor', 'Invoice' );
	add_option( 'lightbox_descriptorcss', '' );
	add_option( 'lightbox_invoicebox2', 'height: 30px; overflow: hidden; text-overflow: ellipsis; width: 80%; max-width: 300px; white-space: nowrap; border: 1px solid black; padding: 5px; vertical-align: middle; font-size: 18px;' );
	add_option( 'lightbox_copycss2', 'display: inline-block; width: 10%; font-size: 25px;  cursor: pointer; vertical-align: middle;' );
	add_option( 'lightbox_btntext2', 'Open wallet' );
	add_option( 'lightbox_bottomtext2', '' );
	register_setting( 'ptrm_options_group', 'currency', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lnbits_url', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lnbits_api_key', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_showptrmtext', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_showptrmcss', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_boxcss2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_blackbgroundcss2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_closecss2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_toptext2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_invoicedescriptor', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_descriptorcss', 'ptrm_callback' );
  register_setting( 'ptrm_options_group', 'lightbox_invoicebox2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_copycss2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_btntext2', 'ptrm_callback' );
	register_setting( 'ptrm_options_group', 'lightbox_bottomtext2', 'ptrm_callback' );
}
add_action( 'admin_init', 'ptrm_register_settings' );

function ptrm_register_options_page() {
	add_options_page('PTRM', 'PTRM', 'manage_options', 'ptrm', 'ptrm_options_page');
}
add_action('admin_menu', 'ptrm_register_options_page');

function ptrm_options_page()
{
?>
<h2 style="text-decoration: underline;">PTRM - Pay to read more</h2>
<form action="options.php" method="post">
  <?php settings_fields( 'ptrm_options_group' ); ?>
  <h3>
    LNBits Settings
  </h3>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lnbits_url">
          LNBits url
        </label>
      </th>
      <td>
        <input id="lnbits_url" name="lnbits_url" placeholder="http://localhost:5000" type="text"
               value="<?php echo get_option('lnbits_url'); ?>"/>
      </td>
    </tr>
  </table>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lnbits_api_key">
          LNBits api key
        </label>
      </th>
      <td>
        <input id="lnbits_api_key" name="lnbits_api_key" placeholder="57sd8Qi6FYr1eZJMyd43tJmmmj35Lb4h"
               type="text"
               value="<?php echo get_option('lnbits_api_key'); ?>"/>
      </td>
    </tr>
  </table>
  <h3>
    Customization
  </h3>
  <strong>
    Currency
  </strong>
  <br><br>
  <input checked id="sats" name="currency" type="radio" value="sats">
  <label for="sats">Sats</label><br>
  <input id="usd" name="currency" type="radio" value="usd">
  <label for="usd">USD</label>
  <script>
    if ("<?php echo get_option('currency'); ?>" == "usd") {
      document.getElementById("usd").checked = true;
    }
  </script>
  <p>
    You can use the above option to set whether your want to use sats or USD as the currency for your ptrms. If
    you use sats, your ptrm should look like [ptrm price="1500"]any wordpress content[/ptrm] if you want to
    charge 0.00001500 btc for your content. If you use USD, your ptrm shortcodes should look like [ptrm
    price="0.01"]any wordpress content[/ptrm] if you want to charge one penny for your content.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_showptrmtext">
          Read More button text
        </label>
      </th>
      <td>
        <input id="lightbox_showptrmtext" name="lightbox_showptrmtext" type="text"
               value="<?php echo get_option('lightbox_showptrmtext'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The text in the Read More button can be customized to say something other than Read More. Whatever you type here
    will show up instead of Read More.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_showptrmcss">
          Read More button css
        </label>
      </th>
      <td>
        <input id="lightbox_showptrmcss" name="lightbox_showptrmcss" type="text"
               value="<?php echo get_option('lightbox_showptrmcss'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css here controls the style of the Read More button. If you wish to control the css of the button using a
    regular css document rather than this field, remove all text from this field and set the css for #showptrm in
    a regular css document.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_boxcss2">
          Lightbox CSS
        </label>
      </th>
      <td>
        <input id="lightbox_boxcss2" name="lightbox_boxcss2" type="text"
               value="<?php echo get_option('lightbox_boxcss2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css in Lightbox CSS will modify the appearance of the lightbox that appears when you click the "Read more"
    button. You can set its background color, width, and other parameters using standard css. If you wish to control
    the css of the lightbox using a regular css document rather than this field, remove all text from this field and
    set the css for #lightbox in a regular css document.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_blackbgroundcss2">
          Black Background CSS
        </label>
      </th>
      <td>
        <input id="lightbox_blackbgroundcss2" name="lightbox_blackbgroundcss2" type="text"
               value="<?php echo get_option('lightbox_blackbgroundcss2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css in Black Background CSS will modify the appearance of the black background that appears behind the
    lightbox. You can set its color, width, and other parameters using standard css. If you wish to control the css
    of the black background using a regular css document rather than this field, remove all text from this field and
    set the css for #blackBackground in a regular css document.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_closecss2">
          X Button CSS
        </label>
      </th>
      <td>
        <input id="lightbox_closecss2" name="lightbox_closecss2" type="text"
               value="<?php echo get_option('lightbox_closecss2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css in X Button CSS will modify the x button which, by default, appears at the top right of the lightbox.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_toptext2">
          Top text
        </label>
      </th>
      <td>
        <input id="lightbox_toptext2" name="lightbox_toptext2" type="text"
               value="<?php echo get_option('lightbox_toptext2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The text in "Top text" will appear at the top of the lightbox.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_invoicedescriptor">
          Invoice Descriptor
        </label>
      </th>
      <td>
        <input id="lightbox_invoicedescriptor" name="lightbox_invoicedescriptor" type="text"
               value="<?php echo get_option('lightbox_invoicedescriptor'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The text in "Invoice descriptor" appears below the qr code.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_descriptorcss">
          Descriptor css
        </label>
      </th>
      <td>
        <input id="lightbox_descriptorcss" name="lightbox_descriptorcss" type="text"
               value="<?php echo get_option('lightbox_descriptorcss'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css in "Descriptor css" controls the style of the invoice descriptor. If you wish to control the css using a
    regular css document rather than this field, remove all text from this field and set the css for
    #invoiceDescriptor in a regular css document.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_invoicebox2">
          Invoice box
        </label>
      </th>
      <td>
        <input id="lightbox_invoicebox2" name="lightbox_invoicebox2" type="text"
               value="<?php echo get_option('lightbox_invoicebox2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css in "Invoice box" controls the style of the box that the invoice's text appears in. If you wish to
    control the css using a regular css document rather than this field, remove all text from this field and set the
    css for #invoiceBox2 in a regular css document.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_copycss2">
          Copy button css
        </label>
      </th>
      <td>
        <input id="lightbox_copycss2" name="lightbox_copycss2" type="text"
               value="<?php echo get_option('lightbox_copycss2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The css in "Copy button css" controls the style of the copy button next to the lightbox. If you wish to control
    the css using a regular css document rather than this field, remove all text from this field and set the css for
    #copyButton2 in a regular css document.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_btntext2">
          Button text
        </label>
      </th>
      <td>
        <input id="lightbox_btntext2" name="lightbox_btntext2" type="text"
               value="<?php echo get_option('lightbox_btntext2'); ?>"/>
      </td>
    </tr>
  </table>
  <p>
    The text in "Button text" will appear in the button in the lightbox.
  </p>
  <table>
    <tr valign="middle">
      <th scope="row">
        <label for="lightbox_bottomtext2">
          Bottom text
        </label>
      </th>
      <td>
        <input id="lightbox_bottomtext2" lightbox_bottomtext2 name="lightbox_bottomtext2" type="text"
               value='<?php echo get_option(''); ?>' />
      </td>
    </tr>
  </table>
  <p>
    The text in "Bottom text" will appear at the bottom of the lightbox after the button.
  </p>
  <?php  submit_button(); ?>
</form>
<?php
} ?>
