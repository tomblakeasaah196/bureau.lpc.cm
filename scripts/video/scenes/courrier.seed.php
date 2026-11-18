<?php
/**
 * The mail the Courrier video shows (scripts/video/mail.php seed). Written examples, as the owner chose
 * (18/11/2026): LPC's real people and real partners by name, everyday messages written for the video, on a
 * mail server that exists only on the filming machine. Partners' addresses are on example.com (reserved:
 * it can never reach a real company). References are the copy's real ones (PO-2611-00004, its 18 500 FCFA).
 *
 * Nothing is connected in bureau here: Daniel connects his own mailbox on camera, and Timothée adds
 * invoicing@lpc.cm and its people on camera. Passwords are the fake server's, for the copy only.
 *
 * In scope from mail.php: $fake (FakeMailServer), video_mail_ago(hours).
 */
use LPC\Mail\Tests\FakeMailServer;

const VIDEO_MAIL_PASSWORDS = [
    'daniel.massomba@lpc.cm'   => 'Courrier-Daniel-2026',
    'timothee.massomba@lpc.cm' => 'Courrier-Timothee-2026',
    'invoicing@lpc.cm'         => 'Courrier-Factu-2026',
];
foreach (VIDEO_MAIL_PASSWORDS as $box => $pw) {
    $fake->addMailbox($box, $pw);
}

/** A one-page PDF with these lines, so an attachment opens on something real-looking. */
function video_pdf(string $title, array $lines): string
{
    $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'Windows-1252//TRANSLIT', $s));
    $y = 760;
    $text = "BT /F1 18 Tf 60 {$y} Td (" . $esc($title) . ") Tj ET\n";
    foreach ($lines as $l) {
        $y -= 26;
        $text .= "BT /F1 12 Tf 60 {$y} Td (" . $esc($l) . ") Tj ET\n";
    }
    $objs = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
        "<< /Length " . strlen($text) . " >>\nstream\n{$text}endstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$o}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

$msg = fn (array $a) => FakeMailServer::message(...$a);
$seen = ['\\Seen'];

// ---------------------------------------------------------------- Daniel's own mailbox
$D = 'daniel.massomba@lpc.cm';
$fake->deliver($D, 'INBOX', $msg(['from' => 'Timothée MASSOMBA <timothee.massomba@lpc.cm>', 'to' => $D,
    'subject' => 'Bienvenue sur le Courrier', 'date' => video_mail_ago(170),
    'text' => "Daniel,\n\nÀ partir de cette semaine, le courrier de l'équipe se lit dans le bureau. Connecte ta boîte quand tu peux.\n\nTimothée"]), $seen);
$fake->deliver($D, 'INBOX', $msg(['from' => 'Luc NANA <luc.nana@gmail.com>', 'to' => $D,
    'subject' => 'Bulletins d\'octobre', 'date' => video_mail_ago(150),
    'text' => "Bonjour Daniel,\n\nLes bulletins d'octobre sont validés. Merci de les classer.\n\nLuc"]), $seen);
$fake->deliver($D, 'INBOX', $msg(['from' => 'Timothée MASSOMBA <timothee.massomba@lpc.cm>', 'to' => $D,
    'subject' => 'Point budget lundi à 9 h', 'date' => video_mail_ago(27),
    'text' => "Daniel,\n\nPoint sur le budget de décembre lundi à 9 h, dans mon bureau. Apporte la situation de trésorerie.\n\nTimothée"]));
$fake->deliver($D, 'INBOX', $msg(['from' => 'Bertin CHADEU <bertin.chadeu@gmail.com>', 'to' => $D,
    'subject' => 'Réception de la commande PO-2611-00004', 'date' => video_mail_ago(6),
    'text' => "Bonjour Daniel,\n\nLa marchandise de SOURCE DU PAYS (commande PO-2611-00004) est arrivée ce matin, complète. Le bon de réception signé est en pièce jointe.\n\nBertin",
    'attachments' => [['name' => 'Bon-de-reception-PO-2611-00004.pdf', 'mime' => 'application/pdf',
        'content' => video_pdf('Bon de réception', ['Commande : PO-2611-00004', 'Fournisseur : SOURCE DU PAYS', 'Reçu le : ' . date('d/m/Y'), 'Contrôle : complet, conforme', 'Réceptionné par : Bertin CHADEU'])]]]));
$fake->deliver($D, 'INBOX', $msg(['from' => 'Luc NANA <luc.nana@gmail.com>', 'to' => $D,
    'subject' => 'Clôture de novembre : pièces à rassembler', 'date' => video_mail_ago(2),
    'text' => "Bonjour Daniel,\n\nPour la clôture de novembre, merci de rassembler d'ici vendredi :\n- les relevés de CCA Bank et de MTN Mobile Money ;\n- les factures fournisseurs reçues ce mois ;\n- les bons de réception signés.\n\nMerci,\nLuc"]));
$fake->deliver($D, 'INBOX', $msg(['from' => 'SOURCE DU PAYS <info@sourcedupays.example.com>', 'to' => $D,
    'subject' => 'Nos tarifs de décembre', 'date' => video_mail_ago(30),
    'text' => 'Bonjour, voici nos tarifs de décembre.',
    'html' => '<p>Bonjour,</p><p>Voici nos tarifs de décembre.</p><p><img src="https://www.example.com/sdp/tarifs-decembre.png" alt="Tarifs" width="480"></p><p>Le service commercial</p>']));

// ---------------------------------------------------------------- invoicing@lpc.cm (shared)
$F = 'invoicing@lpc.cm';
$fake->deliver($F, 'INBOX', $msg(['from' => 'SMART LOGISTICS AND SERVICES LTD <finance@smartls.example.com>', 'to' => $F,
    'subject' => 'Avis de virement', 'date' => video_mail_ago(120),
    'text' => "Bonjour,\n\nNous vous informons du virement de votre facture, effectué ce jour.\n\nService financier"]), $seen);
$n1 = 'nofia-1@nofia.example.com';
$fake->deliver($F, 'INBOX', $msg(['from' => 'NOFIA SA <achats@nofia.example.com>', 'to' => $F, 'messageId' => $n1,
    'subject' => 'Relevé de compte au 15 novembre', 'date' => video_mail_ago(52),
    'text' => "Bonjour,\n\nPourriez-vous nous envoyer notre relevé de compte au 15 novembre ? Notre comptabilité en a besoin pour son rapprochement.\n\nCordialement,\nService achats, NOFIA SA"]), $seen);
$fake->deliver($F, 'INBOX.Sent', $msg(['from' => 'INVOICING-LPC <invoicing@lpc.cm>', 'to' => 'NOFIA SA <achats@nofia.example.com>', 'messageId' => 'nofia-2@lpc.cm',
    'inReplyTo' => $n1, 'references' => "<{$n1}>", 'subject' => 'RE: Relevé de compte au 15 novembre', 'date' => video_mail_ago(48),
    'text' => "Bonjour,\n\nC'est noté : nous vous l'envoyons dès la clôture de la quinzaine.\n\nLa Petite Cour"]), $seen);
$fake->deliver($F, 'INBOX', $msg(['from' => 'NOFIA SA <achats@nofia.example.com>', 'to' => $F, 'messageId' => 'nofia-3@nofia.example.com',
    'inReplyTo' => 'nofia-2@lpc.cm', 'references' => "<{$n1}> <nofia-2@lpc.cm>", 'subject' => 'RE: Relevé de compte au 15 novembre', 'date' => video_mail_ago(4),
    'text' => "Bonjour,\n\nNous n'avons toujours rien reçu. Pouvez-vous nous l'envoyer aujourd'hui ? Nous voudrions aussi le détail des bonbonnes consignées.\n\nMerci,\nService achats, NOFIA SA"]));
$fake->deliver($F, 'INBOX', $msg(['from' => 'BOULANGERIE SAKER <compta@saker.example.com>', 'to' => $F,
    'subject' => 'Duplicata de facture', 'date' => video_mail_ago(9),
    'text' => "Bonjour,\n\nNous avons égaré votre dernière facture. Pouvez-vous nous en renvoyer un duplicata ?\n\nCordialement,\nComptabilité, BOULANGERIE SAKER"]));
$fake->deliver($F, 'INBOX', $msg(['from' => 'SOURCE DU PAYS <factures@sourcedupays.example.com>', 'to' => $F,
    'subject' => 'Facture SDP-2611-0412 : commande PO-2611-00004', 'date' => video_mail_ago(3),
    'text' => "Bonjour,\n\nVeuillez trouver ci-joint notre facture SDP-2611-0412 pour la commande PO-2611-00004, d'un montant de 18 500 FCFA, payable à 30 jours.\n\nService facturation, SOURCE DU PAYS",
    'attachments' => [['name' => 'Facture-SDP-2611-0412.pdf', 'mime' => 'application/pdf',
        'content' => video_pdf('Facture SDP-2611-0412', ['Client : LA PETITE COUR SARL, Douala', 'Commande : PO-2611-00004', 'Montant : 18 500 FCFA', 'Échéance : 30 jours', 'SOURCE DU PAYS'])]]]));
