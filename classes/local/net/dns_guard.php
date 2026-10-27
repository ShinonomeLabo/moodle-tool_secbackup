<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_secbackup\local\net;

/**
 * Checks, before anything is sent, that a host name leads to AWS.
 *
 * Every answer must be inside the AWS ranges: the answers of the DNS over HTTPS resolvers and,
 * unless an HTTP proxy looks the name up, the answer of the operating system. A SOCKS5 proxy does
 * not look the name up (this server does, before it talks to the proxy), so there the answer of
 * the operating system counts and is the address to pin. See proxy_settings::resolves_names().
 * Equality is not required, because CloudFront answers each resolver with addresses near it. Any
 * CloudFront address routes the connection to the right distribution by its name, so an address in
 * the range cannot lead somewhere else.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dns_guard {
    /** @var aws_ranges AWS ranges. */
    private aws_ranges $ranges;

    /** @var doh_resolver[] DNS over HTTPS resolvers. */
    private array $doh;

    /** @var callable Looks up IPv4 addresses with the operating system. */
    private $oslookup;

    /**
     * Constructor.
     *
     * @param aws_ranges $ranges AWS ranges.
     * @param doh_resolver[] $doh DNS over HTTPS resolvers.
     * @param callable|null $oslookup Lookup with the operating system; null for gethostbynamel().
     */
    public function __construct(aws_ranges $ranges, array $doh, ?callable $oslookup = null) {
        $this->ranges = $ranges;
        $this->doh = $doh;
        $this->oslookup = $oslookup ?? static fn(string $host): array => gethostbynamel($host) ?: [];
    }

    /**
     * The guard with the ranges in use (the stored list or the bundled one) and the public resolvers.
     *
     * @return self
     */
    public static function create(): self {
        return new self(aws_ranges::current(), doh_resolver::all());
    }

    /**
     * Names of the DNS over HTTPS resolvers.
     *
     * @return string[]
     */
    public function resolver_names(): array {
        return array_map(static fn(doh_resolver $resolver): string => $resolver->name(), $this->doh);
    }

    /**
     * Asks every source for the host's addresses.
     *
     * @param string $host Host name.
     * @param bool $viaproxy Whether an HTTP proxy resolves the name (proxy_settings::resolves_names()).
     * @return dns_report
     */
    public function inspect(string $host, bool $viaproxy): dns_report {
        $doh = [];
        $connectfailed = $this->doh !== [];
        foreach ($this->doh as $resolver) {
            try {
                $doh[$resolver->name()] = $resolver->lookup($host);
                $connectfailed = false;
            } catch (transport_exception $e) {
                $doh[$resolver->name()] = null;
                $connectfailed = $connectfailed
                    && $e->debuginfo === $resolver->name() . ', ' . transport_exception::CONNECT_FAILED;
            }
        }
        return new dns_report($doh, $viaproxy ? null : ($this->oslookup)($host), $connectfailed);
    }

    /**
     * The problem found in a report, if any.
     *
     * @param dns_report $report The report.
     * @param string $service AWS service the host must belong to.
     * @return string|null Error code, or null when the answers are acceptable.
     */
    public function problem(dns_report $report, string $service): ?string {
        $answered = array_filter($report->doh, static fn(?array $addresses): bool => $addresses !== null);
        if ($answered === []) {
            return 'dns_doh_unavailable';
        }
        foreach ($answered as $addresses) {
            if ($addresses === []) {
                return 'dns_not_found';
            }
            if (!$this->all_inside($addresses, $service)) {
                return 'dns_outside_aws';
            }
        }
        if ($report->os === null) {
            return null;
        }
        if ($report->os === []) {
            return 'dns_os_failed';
        }
        return $this->all_inside($report->os, $service) ? null : 'dns_os_mismatch';
    }

    /**
     * Checks a host and returns the address to connect to.
     *
     * @param string $host Host name.
     * @param bool $viaproxy Whether an HTTP proxy resolves the name (proxy_settings::resolves_names()).
     * @param string $service AWS service the host must belong to.
     * @return string|null The address to pin, or null when an HTTP proxy resolves the name.
     * @throws transport_exception When the check fails.
     */
    public function check(string $host, bool $viaproxy, string $service): ?string {
        $report = $this->inspect($host, $viaproxy);
        $problem = $this->problem($report, $service);
        if ($problem !== null) {
            $failedatconnect = $problem === 'dns_doh_unavailable' && $report->connectfailed;
            throw new transport_exception($problem, $failedatconnect ? transport_exception::CONNECT_FAILED : null);
        }
        return $report->pinned();
    }

    /**
     * Whether all addresses belong to a service.
     *
     * @param string[] $addresses Addresses.
     * @param string $service AWS service.
     * @return bool
     */
    private function all_inside(array $addresses, string $service): bool {
        foreach ($addresses as $address) {
            if (!$this->ranges->contains($address, $service)) {
                return false;
            }
        }
        return true;
    }
}
