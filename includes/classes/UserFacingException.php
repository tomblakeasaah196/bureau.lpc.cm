<?php
/**
 * includes/classes/UserFacingException.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — an exception whose message is safe, and intended, to show
 * the person who triggered it.
 *
 * WHY
 *   The API controllers end in a blanket handler:
 *
 *       } catch (Exception $e) {
 *           error_log('API error: ' . $e->getMessage());
 *           echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
 *       }
 *
 *   That is right for an unexpected failure — a PDO error can carry table
 *   names, column names and fragments of SQL, and none of that belongs in a
 *   browser. But the same handler was also swallowing messages that had been
 *   written specifically for the operator, and had been carefully formatted for
 *   them:
 *
 *       throw new Exception(sprintf(
 *           "Ristourne insuffisante : solde %s FCFA, demandé %s FCFA.", …));
 *
 *   Somebody who tried to spend more rebate than they had was told
 *   "Erreur serveur. Veuillez réessayer.", so they retried, and it failed
 *   again, and the module looked broken when it was in fact working correctly
 *   and refusing an invalid order. Every business rule in the purchase module
 *   had this problem.
 *
 * USE
 *   Throw UserFacingException for anything the operator can act on — a
 *   validation failure, a business rule, a missing configuration they can fix.
 *   Throw a plain Exception (or let PDO throw) for anything else; those stay
 *   generic to the client and go to the error log in full.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

class UserFacingException extends Exception
{
    /**
     * HTTP status to send. Defaults to 422 Unprocessable Content: the request
     * was well-formed and understood, and was refused on its merits. 500 would
     * be wrong — nothing failed — and it pollutes error-rate monitoring with
     * events that are the system working as designed.
     */
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
