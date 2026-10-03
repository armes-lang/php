# Reference benchmark record

Measured locally on 2026-10-01, Apple M4 Max arm64, Node 22.14.0 and PHP 8.4.13. These are single-process development microbenchmarks, not production guarantees or statistical confidence intervals. No browser latency claim is made. The original TS source was copied before this implementation; PHP baseline is commit `1fa0680621388daf074fa6be18a061e4981eb12d`. Baseline and current reference runs use the same TS script, input and installed dependency environment.

## Comparable existing workloads

| TS workload | Iterations | Before (ms/op) | After (ms/op) |
| --- | ---: | ---: | ---: |
| Parse 1,000 explicit legacy refs | 50 | 1.6795 | 1.7017 |
| Cold session + resolve 1,000 legacy refs | 25 | 93.6448 | 40.0809 |
| Invalidate revision + resolve 1,000 legacy refs | 25 | 121.5520 | 24.2313 |
| Cached snapshot | 10,000 | below 0.0001 | below 0.0001 |
| Existing core JSON parse (1,000 cards) | 100 | 8.562 | 8.430 |
| Existing core resolve, already parsed tree | 50 | 2.356 | 2.316 |
| Existing resolve + HTML render, already parsed tree | 25 | 7.594 | 6.514 |

The existing core benchmark's labels say “parse + resolve”; the timed resolve/render portions actually reuse a previously parsed tree. The table describes the measured operations. The new legacy parse timing is slightly higher in this run; do not infer that every operation improved. Child indexes and reuse of compiled selectors reduce the large reference-session costs.

| Existing PHP workload | Before | After |
| --- | ---: | ---: |
| Parse + normalize, 1,000 iterations | 17.218 µs/op | 18.545 µs/op |
| Parse + resolve, 1,000 iterations | 42.909 µs/op | 45.023 µs/op |
| Parse + resolve + HTML mapping, 1,000 iterations | 61.829 µs/op | 64.132 µs/op |
| Markup normalize, 825,408-byte HTML | 7.761 ms | 8.022 ms |
| Markup roundtrip reparse | 6.959 ms | 7.277 ms |
| Markup structural match | yes | yes |

PHP markup outputs were written in temporary benchmark directories to preserve workspace artifacts. Both runs produced 882,127-byte compact JSON and 813,691-byte emitted HTML. These small differences need repeated runs before treating them as a regression trend.

## New capabilities (no historical equivalent)

`node --expose-gc --loader ts-node/esm benchmarks/reference-benchmark.ts` measured:

| Workload | Iterations | ms/op |
| --- | ---: | ---: |
| Compile resource selector with 32 data steps | 10,000 | 0.0078 |
| Deep read after refresh, cached parsed resource | 100 | 0.1175 |
| Project 1,000 values after refresh | 50 | 0.2906 |
| Cold session: 1,000 reads across 10 resources | 25 | 50.7193 |
| Refreshed revision: 1,000 reads across 10 resources | 25 | 55.1826 |
| Cached snapshot for that graph | 10,000 | below 0.0001 |

Ten retained sessions of that 1,000-read graph added 24,334,592 heap bytes after explicit GC. This includes their immutable authored trees, snapshots, descriptors, dependency edges and resource caches; it is not memory per individual reference. Process memory and JIT/GC vary. Refresh invalidates evaluated external reads conservatively, while parsed resources are reused by content/format/mode/parse limits. Thus refreshed cross-file evaluation is not advertised as an incremental O(changed-values) path.

Reproduce comparable reference work with `benchmarks/reference-baseline.ts`; repeat runs independently before making release budgets. Reproduce ordinary workloads with `npm run benchmark`, PHP `php benchmarks/core-benchmark.php` and `php benchmarks/markup-benchmark.php`. Future release profiling should include 10k/100k graphs, real filesystem/workspace latency, cancellation under load, editor resource edits, browser heap retention and repeated memory measurements. The recorded 1k workload is a baseline, not a claim that those larger sizes were tested.

The final identity/markup verification reran the PHP benchmarks: parse/normalize 24.020 µs/op, parse/resolve 63.738 µs/op, and HTML mapping 72.886 µs/op; markup normalize 7.976 ms and roundtrip 7.349 ms, with structural match and identical output sizes. These individual runs demonstrate timing variation; the earlier table remains the paired measurement record, not a final-build performance guarantee.
