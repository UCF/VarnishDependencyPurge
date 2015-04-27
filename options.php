<div class="wrap">
  <h2>Varnish Dependency Purger</h2>
	<form method="post" action="options.php">
		<?php settings_fields('vdp-settings-group'); ?>
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
			<tr valign="top">
				<th scope="row">Varnish Ban Threshold</th>
				<td>
					<input type="number" name="varnish-threshold"><?php echo get_option('varnish-threshold');?> />
					<p>
						This number is used to indicate how many posts can be banned individually.</p>
					</p>
					<p>
						If more than this number of posts is queued up to be banned, the plugin will ban the entire site.</p>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
