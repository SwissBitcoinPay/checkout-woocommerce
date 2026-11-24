<?php
/**
 * The integration key's field: always empty, the key saved is never sent to the browser.
 *
 * Every value is escaped here.
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the field shows (Gateway::generate_sattleme_key_html()).
 *
 * @var \Sattleme\WooCommerce\Admin\KeyFieldView $sattleme
 */
$sattleme_field = $sattleme->field_key;
?>
<tr valign="top">
	<th scope="row" class="titledesc">
		<label for="<?php echo esc_attr( $sattleme_field ); ?>"><?php echo esc_html( $sattleme->title ); ?></label>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text"><span><?php echo esc_html( $sattleme->title ); ?></span></legend>
			<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $sattleme_field ); ?>" id="<?php echo esc_attr( $sattleme_field ); ?>" value="" autocomplete="new-password" spellcheck="false"
				placeholder="<?php echo $sattleme->has_key ? esc_attr__( 'Saved: enter a new key to replace it', 'sattleme-for-woocommerce' ) : esc_attr( 'sattleme_ck_…' ); ?>" />
			<p class="description"><?php echo esc_html( $sattleme->description ); ?></p>
		</fieldset>
	</td>
</tr>
