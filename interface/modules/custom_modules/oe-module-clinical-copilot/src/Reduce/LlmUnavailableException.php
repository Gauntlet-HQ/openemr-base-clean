<?php

/**
 * Thrown when the LLM provider cannot be reached or has no usable credentials.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * The one signal that drives I6/I11 degradation on the reduce side: no ADC
 * configured (the default state of this dev/test environment -- there is no
 * GCP project here), the Vertex endpoint unreachable, or the provider
 * rejecting the request for an auth reason. Deliberately a single exception
 * class with a machine-readable {@see self::reason()} rather than a
 * hierarchy -- every caller-visible branch is the same ("no narrative for
 * this attempt"); only the log line needs to know why.
 */
final class LlmUnavailableException extends \RuntimeException
{
    public const REASON_NO_CREDENTIALS = 'no_credentials';
    public const REASON_UNREACHABLE = 'unreachable';
    public const REASON_PROVIDER_ERROR = 'provider_error';

    public function __construct(
        private readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function noCredentials(\Throwable $previous): self
    {
        return new self(
            self::REASON_NO_CREDENTIALS,
            'LLM provider credentials could not be resolved (no ADC configured in this environment)',
            $previous,
        );
    }

    public static function unreachable(\Throwable $previous): self
    {
        return new self(
            self::REASON_UNREACHABLE,
            'LLM provider endpoint could not be reached',
            $previous,
        );
    }

    public static function providerError(\Throwable $previous): self
    {
        return new self(
            self::REASON_PROVIDER_ERROR,
            'LLM provider rejected the request',
            $previous,
        );
    }

    /**
     * One of the REASON_* constants -- surfaced on the degradation signal so
     * dashboards/alerts (U12) can distinguish "nobody configured credentials"
     * from "the network is down" without parsing message text.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * A rich, developer-facing description of what actually went wrong: the
     * machine reason category, this exception's own message, and the
     * underlying provider/transport message (Guzzle/cURL/HTTP-body or ADC
     * error). This is NOT physician-safe copy -- it is threaded into the
     * degradation return value ONLY as a temporary debugging aid while the
     * serving path is being brought up, so an operator can see "no key" vs
     * "DNS failure" vs "HTTP 403 API_KEY_INVALID" straight from the response.
     *
     * TODO(pre-prod): stop surfacing this in the user-facing return value --
     * keep it to logs/traces only (it can contain internal hostnames and
     * provider error bodies). See ARCHITECTURE.md §4 / the "never expose
     * getMessage() to users" rule.
     */
    public function detail(): string
    {
        $detail = $this->reason . ': ' . $this->getMessage();
        $previous = $this->getPrevious();
        if ($previous !== null && trim($previous->getMessage()) !== '') {
            $detail .= ' -- ' . $previous->getMessage();
        }

        return $detail;
    }

    /**
     * Short, physician-safe copy for chat/synthesis degrade banners.
     */
    public function degradedMessage(): string
    {
        $detail = strtolower($this->getPrevious()?->getMessage() ?? $this->getMessage());
        if ($this->reason === self::REASON_PROVIDER_ERROR
            && (str_contains($detail, '429') || str_contains($detail, 'quota') || str_contains($detail, 'resource_exhausted'))) {
            return 'Gemini API rate limit reached -- wait about a minute and try again; the chart is still current';
        }

        return 'narrative unavailable -- the assistant is temporarily unreachable; the chart is still current';
    }
}
