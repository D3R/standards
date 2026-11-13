# Discovery scope and pilot limitations

The user-facing setup and CI commands are in the [README](../README.md#mago-static-analysis-irm-1004-pilot). This document records the coverage boundary, not extra installation steps.

## Ownership and isolation

`bin/d3r-analysis` explicitly avoids the consuming application's Composer loader. Its SDK/parser loader belongs to a separate locked public-dependency project in the tool cache. Root project Composer maps and `vendor/d3r.php` are parsed as syntax/data; project PHP is not evaluated. No provider/model is constructed and no configuration, database, queue or external logging is initialized.

The package root adds no SDK/parser runtime dependency to a consuming application's dependency graph. The optional command requires PHP 8.2+; updating coding-standard files alone does not run or install Mago. Tooling installation is explicit through `setup` or first use of `discover`/`check`. Pinned dependency updates are separate from ordinary project dependency updates.

## What is automatic

- Existing generated provider order, Composer classmap, PSR-4 and legacy PSR-0 source paths.
- Literal `$models`, static properties, parent model-list merges and simple installed-class/interface conditions with bounded source-only interpretation; alias candidates retain their declaring provider. Unrecognised expressions remain gaps.
- Concrete model/parent graph and breadth-first nested trait XML in FieldBroker structural order.
- XML fieldset/duplicate precedence and False skip behaviour; lowercase type names are normalised.
- Field-class lookup from the default naming convention and literal binding candidates.
- Known XML properties inside and outside model methods, the inherited model `id` property, and conservative magic-call signatures. No-argument field calls return the default field object; calls with arguments return a precise object only for an exact `return $this` expose contract. Binding overrides remain mixed because container execution is not inferred. Raw values remain mixed. Native declared properties retain their contracts.
- Existing native method names reserved by token parsing, so XML annotations do not retype them.
- Fresh generation on every check; source hashes include parsed provider/model/trait/field inputs, Composer metadata and absent adjacent XML paths. No persistent analyser cache is assumed.

## What remains incomplete

A source model-list declaration is not a complete container execution trace. Factories, arbitrary conditionals, provider boot-time mutations, inherited registration control flow, dynamic alias returns and collection generics are not inferred. No authoritative container alias return types are emitted. Trait adaptations affecting the requested metadata methods are reported for review.

In the initial Soho package-command run, 326 model candidates were structurally resolved and 299 generated metadata successfully. Thirty gaps were reported, including provider patterns and implicit XML labels. Subsequent adapter passes resolved the Soho declarations through bounded source rules. The reviewed legacy ModuleFolder implementation is accepted only when its default module paths contain no model files; non-empty module folders remain explicit gaps until instantiability equivalence is tested. Those counts are a snapshot, not a completeness guarantee. A model whose metadata cannot be safely generated is omitted and reported; it is not silently accepted.

Dynamic `getXmlSources()`, including blocks' container-driven sources, requires a shared framework/package rule. Extra XML methods that cannot be resolved cause a coverage gap. Unknown field classes and implicit labels requiring an unreviewed Unicode transliteration remain gaps. ASCII implicit labels follow D3R slugging, and arbitrary well-formed XML container roots are accepted just as FieldBroker accepts them. Projects should not maintain a per-field manifest to work around these patterns; improve the shared discovery rule instead.

The optional finder patch is used only when the installed base-model source hash matches the reviewed d3r/core v5.359.5 source. It describes `findById()` scalar model-or-false and array collection-or-false results. Other core revisions retain their source contracts until reviewed; array inputs are not part of the original positive/negative acceptance matrix.

The pilot currently has no suppression configuration, baseline, watch mode, full alias return provider, XML relationship generics, formatter or linter integration. The native Mago SDK is version-pinned because it is not assumed to match PHPStan/Psalm extension APIs. Generated `models.mago.php` is property-only; native providers also establish property presence in model bodies and resolve magic methods. Patched `@method` declarations were insufficient in the tested release and could refine existing native methods.

## Verification

Run the standalone source-only integration fixture after setup:

```sh
php tests/static-analysis-smoke.php /path/to/tool-cache
```

It exercises real Mago clean/diagnostic/incomplete-discovery exits, automatic XML methods, typo detection and a sentinel detecting accidental source execution. Temporary fixture reports are retained outside the project. The earlier external adapter harness also covers XML precedence, XXE rejection, freshness, native-method precedence and restoration. It remains investigation evidence, not a production PHPStan dependency.

Do not present an advisory CI job, local annotation output, or YAML validation as a successful hosted Actions run. Package push, consumer pin, local installed command execution and hosted CI have separate evidence in the ticket handoff. Before a general release, review residual diagnostics and shared discovery boundaries, validate another real project, prove hosted CI and expand package-owned regression coverage. Mago remains the only installed analysis tool in this package workflow.
