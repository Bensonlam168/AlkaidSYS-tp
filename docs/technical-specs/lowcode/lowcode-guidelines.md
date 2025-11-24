# Low-code Development Guidelines

## 1. Scope and Goals

This document defines how the low-code subsystem (Domain\\Lowcode, Infrastructure\\Lowcode, admin UI) MUST be used as the single source of truth for dynamic collections, fields and relationships in AlkaidSYS.

## 2. Single Source of Truth

- Low-code metadata stored under `Domain\\Lowcode` **MUST** be treated as the authoritative model for dynamic entities.
- Code that manipulates collections and fields **MUST** go through low-code services; direct access to legacy collection abstractions is forbidden.

## 3. Legacy Restrictions

- New features **MUST NOT** introduce new uses of legacy collection APIs (`Domain\\Model\\Collection`, `Infrastructure\\Collection\\CollectionManager`, etc.).
- Where legacy code is still required for compatibility, it **MUST** be wrapped in adapter layers clearly marked as deprecated.

## 4. Modelling Conventions

- Collection and field names **MUST** follow the same naming rules as database tables and columns.
- Relations between collections **SHOULD** be explicit and documented, including cardinality and ownership.

## 5. Phase Model (Low-code)

- **Phase 1**: Low-code covers new features; legacy models remain for existing flows.
- **Phase 2**: Business-critical flows SHOULD be fully migrated to low-code, and legacy models SHOULD be phased out according to the deprecation plan.

