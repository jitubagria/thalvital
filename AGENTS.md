# ThalVital agent notes

This is a PHP 8.1+ server-rendered application. Keep patient-data queries parameterized, preserve the permanent Aadhaar salt, and never store raw Aadhaar values.

## Documentation lives in the wiki

Living documentation is in `D:\project-wiki\10-systems\thalvital\`. Before durable changes, run `bash D:/project-wiki/_tools/query-project.sh thalvital` and start at `thalvital-overview.md`.

| Change | Wiki page |
|---|---|
| Components/request flow | `thalvital-architecture.md` |
| Schema/entities | `thalvital-data-model.md` |
| Progress/verification | `thalvital-status.md`, `thalvital-tests.md` |
| Risks/future work | `thalvital-blockers.md`, `thalvital-roadmap.md` |

Run the wiki lint and alignment checks after durable updates. Final coding reports must state the wiki-sync decision.
