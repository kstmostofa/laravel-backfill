# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | Yes |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub instead: open the repository's **Security** tab and choose **Report a vulnerability**. You can also email mostofa122@mail.com.

Include what you found, how to reproduce it, and which version you tested. You should get a reply within a few days. Once a fix is released, the advisory will be published with credit to you unless you would rather stay anonymous.

## Worth knowing

The dashboard and the operator panel can start and cancel data changes over production tables. Both are closed by default outside the `local` environment and stay closed until you grant access with `Dashboard::auth()` and `Dashboard::operatorAuth()`. A report that one can be reached without passing its gate is treated as a vulnerability.
