<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'troyes', get_stylesheet_uri(), array(), '1.0' );
} );
add_action( 'after_setup_theme', function () {
    add_theme_support( 'editor-styles' );
    add_editor_style( 'style.css' );
} );

// Activate each inbox explicitly. Configure these constants in wp-config.php.
function troyes_inbox( $kind ) {
    $key = $kind === 'visite' ? 'TROYES_VISITE_EMAIL' : 'TROYES_PRIERE_EMAIL';
    return defined( $key ) && is_email( constant( $key ) ) ? constant( $key ) : '';
}

add_shortcode( 'troyes_demande', function ( $attributes ) {
    $kind = ( $attributes['type'] ?? '' ) === 'visite' ? 'visite' : 'priere';
    if ( ! troyes_inbox( $kind ) ) {
        return '<p>Pour le moment, vous pouvez rencontrer l’équipe sur place le samedi matin.</p>';
    }
    $result = sanitize_key( $_GET['retour'] ?? '' );
    if ( $result === 'envoye' ) {
        return '<p role="status">Merci. Votre demande a été transmise à la messagerie de l’équipe.</p>';
    }
    ob_start();
    if ( $result === 'erreur' ) {
        echo '<p role="alert">La demande n’a pas pu être envoyée. Vérifiez les champs ou réessayez plus tard.</p>';
    }
    ?>
    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
        <input type="hidden" name="action" value="troyes_demande">
        <input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
        <?php wp_nonce_field( 'demande_' . $kind ); ?>
        <label>Votre prénom <input name="prenom" required maxlength="80" autocomplete="given-name"></label>
        <label>Votre e-mail <input type="email" name="courriel" required maxlength="254" autocomplete="email"></label>
        <?php if ( $kind === 'visite' ) : ?>
        <label>Date de votre venue <input type="date" name="date" required min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
        <?php else : ?>
        <label>Votre demande <textarea required name="message" rows="5" maxlength="1200"></textarea></label>
        <?php endif; ?>
        <label><input type="checkbox" required name="accord" value="1"> J’accepte que l’équipe reçoive ces informations par e-mail pour traiter ma demande.</label>
        <p><a href="<?php echo esc_url( home_url( '/informations/' ) ); ?>">Utilisation des données et contact</a></p>
        <button>Envoyer ma demande</button>
    </form>
    <?php
    return ob_get_clean();
} );

function troyes_send_request() {
    $input = wp_unslash( $_POST );
    $kind = $input['kind'] ?? '';
    if ( ! in_array( $kind, array( 'visite', 'priere' ), true ) ) {
        wp_die( 'Type de demande inconnu.' );
    }
    check_admin_referer( 'demande_' . $kind );
    $recipient = troyes_inbox( $kind );
    $name = sanitize_text_field( $input['prenom'] ?? '' );
    $email = is_email( $input['courriel'] ?? '' );
    $message = sanitize_textarea_field( $input['message'] ?? '' );
    $date = sanitize_text_field( $input['date'] ?? '' );
    $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
    $valid = $kind === 'visite'
        ? $parsed && $parsed->format( 'Y-m-d' ) === $date && $date >= current_time( 'Y-m-d' )
        : $message !== '' && strlen( $message ) <= 1200;
    $limit = 'troyes_' . wp_hash( $_SERVER['REMOTE_ADDR'] ?? '' );
    $sent = false;
    if ( $recipient && $valid && $name && strlen( $name ) <= 80 && $email && ( $input['accord'] ?? '' ) === '1' && ! get_transient( $limit ) ) {
        set_transient( $limit, 1, MINUTE_IN_SECONDS );
        $sent = wp_mail( $recipient, 'Nouvelle demande — Troyes', implode( "\n", array( $kind, $name, $email, $kind === 'visite' ? $date : $message ) ) );
    }
    $path = $kind === 'visite' ? '/premiere-visite/' : '/priere/';
    wp_safe_redirect( add_query_arg( 'retour', $sent ? 'envoye' : 'erreur', home_url( $path ) ), 303 );
    exit;
}
add_action( 'admin_post_troyes_demande', 'troyes_send_request' );
add_action( 'admin_post_nopriv_troyes_demande', 'troyes_send_request' );

// Preserve existing pages and editorial changes on reactivation.
add_action( 'after_switch_theme', function () {
    $pages = array(
        'premiere-visite' => array( 'Préparer ma visite', 'Venez librement le samedi matin. Si vous souhaitez prévenir l’équipe de votre venue, vous pouvez le faire ici.', 'visite' ),
        'priere' => array( 'Prier avec nous', 'Demandez comment rejoindre une rencontre en ligne ou partagez une intention avec l’équipe. Évitez de citer des tiers sans leur accord.', 'priere' ),
        'informations' => array( 'Informations du site', 'Les cartes et ressources externes s’ouvrent uniquement lorsque vous suivez leur lien. Les formulaires activés transmettent vos coordonnées à l’équipe par e-mail.', '' ),
    );
    foreach ( $pages as $slug => list( $title, $intro, $kind ) ) {
        if ( get_page_by_path( $slug ) ) { continue; }
        $content = '<!-- wp:paragraph --><p>' . esc_html( $intro ) . '</p><!-- /wp:paragraph -->';
        if ( $kind ) {
            $content .= '<!-- wp:shortcode -->[troyes_demande type="' . $kind . '"]<!-- /wp:shortcode -->';
        }
        wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ) );
    }
} );
