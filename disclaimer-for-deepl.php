<?php
/**
 * Plugin Name: Traduzido com DeepL (checkbox + aviso)
 * Description: Adiciona um checkbox na edição (posts/páginas/CPTs) e, se marcado, insere um aviso em forma de bloco no conteúdo (início ou fim).
 * Version: 1.1.1
 * Author: Opará Tecnologia
 * Author URI: https://opara.me
 * License: GPLv2 or later
 * Text Domain: disclaimer-for-deepl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TVP_META_KEY        = 'tvp_translated_via_plugin';
const TVP_OPTION_TEXT_KEY     = 'tvp_notice_text';
const TVP_OPTION_POSITION_KEY = 'tvp_notice_position';

/**
 * Retorna os post types suportados: posts, pages e CPTs públicos (não built-in também),
 * excluindo anexos.
 *
 * @return string[]
 */
function tvp_get_supported_post_types(): array {
	$post_types = get_post_types(
		array(
			'public' => true,
		),
		'names'
	);

	$post_types = array_values(
		array_filter(
			$post_types,
			static function ( $post_type ) {
				return 'attachment' !== $post_type;
			}
		)
	);

	return $post_types;
}

/**
 * Registra o post meta usado pelo plugin para todos os tipos suportados.
 */
function tvp_register_post_meta(): void {
	$post_types = tvp_get_supported_post_types();

	foreach ( $post_types as $post_type ) {
		register_post_meta(
			$post_type,
			TVP_META_KEY,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'show_in_rest'      => true,
				'default'           => false,
			)
		);
	}
}
add_action( 'init', 'tvp_register_post_meta' );

/**
 * Registra a opção do texto do aviso.
 */
function tvp_register_settings(): void {
	register_setting(
		'tvp_settings',
		TVP_OPTION_TEXT_KEY,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => 'esse post foi traduzido via plugin de IA',
		)
	);
	register_setting(
		'tvp_settings',
		TVP_OPTION_POSITION_KEY,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'before',
		)
	);

	add_settings_section(
		'tvp_main_section',
		__( 'Configurações', 'tvp' ),
		'__return_null',
		'tvp_settings'
	);

	add_settings_field(
		'tvp_notice_text',
		__( 'Texto do aviso', 'tvp' ),
		'tvp_render_notice_text_field',
		'tvp_settings',
		'tvp_main_section'
	);

	add_settings_field(
		'tvp_notice_position',
		__( 'Posição do aviso', 'tvp' ),
		'tvp_render_position_field',
		'tvp_settings',
		'tvp_main_section'
	);
}
add_action( 'admin_init', 'tvp_register_settings' );

/**
 * Render do campo de texto.
 */
function tvp_render_notice_text_field(): void {
	$value = get_option( TVP_OPTION_TEXT_KEY, 'esse post foi traduzido via plugin de IA' );
	wp_editor( $value, TVP_OPTION_TEXT_KEY, array(
		'textarea_name' => TVP_OPTION_TEXT_KEY,
		'media_buttons' => false,
		'textarea_rows' => 5,
		'teeny'         => true,
	) );
	?>
	<p class="description">
		<?php echo esc_html__( 'Este texto será exibido no conteúdo quando o checkbox estiver marcado.', 'tvp' ); ?>
	</p>
	<?php
}

/**
 * Render do campo de posição.
 */
function tvp_render_position_field(): void {
	$value = get_option( TVP_OPTION_POSITION_KEY, 'before' );
	?>
	<select name="<?php echo esc_attr( TVP_OPTION_POSITION_KEY ); ?>" id="<?php echo esc_attr( TVP_OPTION_POSITION_KEY ); ?>">
		<option value="before" <?php selected( $value, 'before' ); ?>><?php esc_html_e( 'Exibir no começo do post', 'tvp' ); ?></option>
		<option value="after" <?php selected( $value, 'after' ); ?>><?php esc_html_e( 'Exibir no final do post', 'tvp' ); ?></option>
	</select>
	<?php
}

/**
 * Cria a página de configurações no admin.
 */
function tvp_add_settings_page(): void {
	add_options_page(
		__( 'Traduzido via plugin', 'tvp' ),
		__( 'Traduzido via plugin', 'tvp' ),
		'manage_options',
		'tvp-settings',
		'tvp_render_settings_page'
	);
}
add_action( 'admin_menu', 'tvp_add_settings_page' );

/**
 * Render da página de configurações.
 */
function tvp_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Traduzido via plugin', 'tvp' ); ?></h1>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'tvp_settings' );
			do_settings_sections( 'tvp_settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Adiciona o metabox com o checkbox na tela de edição para posts, páginas e CPTs públicos.
 */
function tvp_add_metabox(): void {
	$post_types = tvp_get_supported_post_types();

	foreach ( $post_types as $post_type ) {
		add_meta_box(
			'tvp_metabox',
			__( 'Tradução', 'tvp' ),
			'tvp_render_metabox',
			$post_type,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'tvp_add_metabox' );

/**
 * Renderiza o metabox.
 *
 * @param WP_Post $post Post atual.
 */
function tvp_render_metabox( WP_Post $post ): void {
	wp_nonce_field( 'tvp_save_metabox', 'tvp_nonce' );

	$value = (bool) get_post_meta( $post->ID, TVP_META_KEY, true );
	?>
	<p>
		<label for="tvp_translated_via_plugin">
			<input
				type="checkbox"
				id="tvp_translated_via_plugin"
				name="tvp_translated_via_plugin"
				value="1"
				<?php checked( true, $value ); ?>
			/>
			<?php echo esc_html__( 'Traduzido via plugin?', 'tvp' ); ?>
		</label>
	</p>
	<?php
}

/**
 * Salva o valor do checkbox como post meta (para qualquer post type suportado).
 *
 * @param int $post_id ID do post.
 */
function tvp_save_metabox( int $post_id ): void {
	if ( ! isset( $_POST['tvp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tvp_nonce'] ) ), 'tvp_save_metabox' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$post_type = get_post_type( $post_id );

	if ( empty( $post_type ) ) {
		return;
	}

	$supported_post_types = tvp_get_supported_post_types();
	if ( ! in_array( $post_type, $supported_post_types, true ) ) {
		return;
	}

	$is_checked = isset( $_POST['tvp_translated_via_plugin'] ) ? true : false;

	update_post_meta( $post_id, TVP_META_KEY, $is_checked );
}
add_action( 'save_post', 'tvp_save_metabox' );

/**
 * Se marcado, injeta um bloco no início do conteúdo.
 *
 * @param string $content Conteúdo.
 * @return string
 */
function tvp_inject_notice_block( string $content ): string {
	if ( is_admin() ) {
		return $content;
	}

	if ( ! is_singular() ) {
		return $content;
	}

	global $post;

	if ( empty( $post ) || empty( $post->ID ) ) {
		return $content;
	}

	$post_type            = get_post_type( $post->ID );
	$supported_post_types = tvp_get_supported_post_types();

	if ( empty( $post_type ) || ! in_array( $post_type, $supported_post_types, true ) ) {
		return $content;
	}

	$is_translated = (bool) get_post_meta( $post->ID, TVP_META_KEY, true );

	if ( false === $is_translated ) {
		return $content;
	}

	$notice_text = get_option( TVP_OPTION_TEXT_KEY, 'esse post foi traduzido via plugin de IA' );
	$notice_text = trim( (string) $notice_text );

	if ( '' === $notice_text ) {
		return $content;
	}

	$notice_block = "<!-- wp:group {\"style\":{\"spacing\":{\"margin\":{\"bottom\":\"var:preset|spacing|30\"},\"padding\":{\"top\":\"var:preset|spacing|20\",\"right\":\"var:preset|spacing|20\",\"bottom\":\"var:preset|spacing|20\",\"left\":\"var:preset|spacing|20\"}},\"border\":{\"width\":\"1px\"}},\"borderColor\":\"contrast-3\",\"backgroundColor\":\"base-2\",\"layout\":{\"type\":\"constrained\"}} -->\n"
		. "<div class=\"wp-block-group has-border-color has-contrast-3-border-color has-base-2-background-color has-background\" style=\"border-width:1px;margin-bottom:var(--wp--preset--spacing--30);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--20);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--20)\">"
		. $notice_text . "\n"
		. "</div>\n"
		. "<!-- /wp:group -->\n";

	if ( false !== strpos( $content, $notice_text ) ) {
		return $content;
	}

	$position = get_option( TVP_OPTION_POSITION_KEY, 'before' );

	if ( 'after' === $position ) {
		return $content . $notice_block;
	}

	return $notice_block . $content;
}
add_filter( 'the_content', 'tvp_inject_notice_block', 20 );