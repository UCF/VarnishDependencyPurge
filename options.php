<div class="wrap">
  <h2>Varnish Depedency Purger</h2>
	<form method="post" action="options.php">
		<?php settings_fields('vdp-settings-group'); ?>
		<?php do_settings_fields('vdp-settings-page'); ?>
		<?php  ?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row">Varnish Nodes</th>
				<td>
					<textarea name="varnish-nodes"><?php echo get_option('varnish-nodes'); ?></textarea>
					<p>
						<strong>Format:</strong> &lt;ip address or domain name&gt;:&lt;port&gt;. Separate multiple nodes with semicolons.
					</p>
					<p>
						<strong>Status</strong>: <?php  echo (VDP::parse_varnish_nodes(get_option('varnish-nodes')) !== False) ? 'Valid' : 'Invalid'; ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>