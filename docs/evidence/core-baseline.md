# Core exploratory baseline

Observed on **2026-11-27**, PHP **8.5.13**, phpbench **1.7.1**, CLI OPcache and
Xdebug disabled. All six subjects completed without an error. These local
measurements establish a reproducible starting point and an observed improvement;
they are not production capacity or latency SLOs.

The [machine-readable result](../../benchmarks/results/core-2026-11-27.json)
contains per-iteration samples, dispersion, memory observations, the initial run,
the final run and the final production/benchmark-source fingerprint. The
fingerprint algorithm and its input boundary are recorded in that file. The
initial run preceded the scanner change and was not a separate release commit.

## Reproduction

```sh
composer install
composer bench
```

The checked-in `phpbench.json` disables CLI OPcache for comparability. The classes
specify five iterations, 100 revolutions for codec/header/backoff/memory subjects
and 1000 for registry dispatch. Results below use phpbench's estimated mode per
revolution, not a request-latency percentile.

## Final observed values

| Operation | Payload | Mode | Relative standard deviation |
| --- | ---: | ---: | ---: |
| Encode fixed JSON/base64 envelope | 256 B | 1.966 µs | 4.00% |
| Encode fixed JSON/base64 envelope | 64 KiB | 67.052 µs | 6.52% |
| Decode and validate envelope | 256 B | 10.339 µs | 3.08% |
| Decode and validate envelope | 64 KiB | 206.371 µs | 19.94% |
| Header validation | Two string headers, small fixture | 0.586 µs | 45.89% |
| Capped backoff calculation | Ordinal 20, deterministic random callback | 0.439 µs | 2.69% |
| Exact alias/schema registry dispatch | One registered handler | 0.165 µs | 30.16% |
| Memory publication through successful Worker acknowledgment | 256 B | 68.687 µs | 7.62% |

The parameterized core fixture repeats header/backoff subjects with both body
sizes, although those two operations do not consume the body. Both observations
are retained in the JSON. Large relative variation on sub-microsecond operations
makes small differences unsuitable for optimization decisions on this host.

The memory subject includes generating a distinct ID, constructing the message,
encoding, publishing, receiving, decoding, registry dispatch, a real handler
callback and acknowledgment. It is not an isolated measurement of Worker method
call overhead. Its state is recreated between iterations, and each revolution
must produce `Acknowledged`; retained tombstones therefore cannot turn the test
into a repeated no-op publication.

## Measured scanner change

The initial duplicate-member validator scanned every character inside string
values in PHP, including the large base64 body. Syntax/depth validation already
runs through PHP's JSON parser. The implementation now skips ordinary validated
string runs with `strcspn()` while retaining explicit quote/backslash handling and
member-name decoding for duplicate detection.

| Decode fixture | Initial mode | Final mode |
| --- | ---: | ---: |
| 256 B body | 26.654 µs | 10.339 µs |
| 64 KiB body | 2939 µs | 206.371 µs |

The improvement is much larger than the observed noise, but these numbers should
not be extrapolated into an application's throughput. The strict codec datasets,
shared adapter contracts and full core suite passed after the change. No
serialization, content-type, limit or duplicate-key requirement was removed.

Database commits, process contention, network failure and application effects
are absent from these subjects. The [PostgreSQL workload](postgres-baseline.md)
measures those storage operations separately, with explicit durability settings.
