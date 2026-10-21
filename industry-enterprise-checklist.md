# Industry, Business & Enterprise Checklist

A checklist of the business modules, industry-specific features, enterprise requirements, and laws/regulations to consider when building a system for a specific business or organization.

This file is used together with:
- `system-development-checklist.md` — the general foundation of every system
- `security-audit-checklist.md` — security measures and attack tests

> **Important:** Not every item is required for every system. Only the sections that match the client's business, size, and industry apply.

> **COLISAP status (as of Oct 18, 2026):** `[✓]` = the system needs it · `[✗]` = the system does not need it · ~~crossed out~~ = needed and already done.

> **Laws and regulations change.** The laws listed here are a starting point. Always verify the current version of each law, regulation, or agency rule with the official source (e.g. Official Gazette, BIR, BSP, SEC, DOLE, NPC) before building features based on it.

---

# Part A — Common Business Modules (Across Industries)

## A1. 💰 Accounting & Finance

- [✗] Chart of accounts
- [✗] Double-entry bookkeeping (debits and credits balance)
- [✗] General ledger
- [✗] Journal entries
- [✗] Accounts receivable
- [✗] Accounts payable
- [✗] Bank accounts and bank reconciliation
- [✗] Cash management / petty cash
- [✗] Fiscal year and accounting periods
- [✗] Period closing (locking closed months)
- [✗] Trial balance
- [✗] Income statement (profit and loss)
- [✗] Balance sheet
- [✗] Cash flow statement
- [✗] Budgeting and budget vs. actual
- [✗] Cost centers / departments
- [✗] Fixed assets and depreciation
- [✗] Multi-currency and exchange rates
- [✗] Tax computation (VAT, percentage tax, withholding tax)
- [✗] Accounting standards compliance (PFRS / IFRS)
- [✗] Audit trail for all financial records
- [✗] No deletion of posted transactions (reversal entries only)

## A2. 🧾 Invoicing, Billing & Receipts

- [✗] Sales invoices
- [✗] Billing statements
- [✗] Receipts / acknowledgment receipts
- [✗] Credit memos / debit memos
- [✗] Sequential, non-reusable invoice numbering
- [✗] Voided invoices kept and marked, not deleted
- [✗] Tax breakdown on invoices (VATable, VAT-exempt, zero-rated, VAT amount)
- [✗] Required invoice information per BIR rules (TIN, business name, address, etc.)
- [✗] Senior citizen discount (20%) and VAT exemption handling
- [✗] PWD discount (20%) and VAT exemption handling
- [✗] Solo parent discount handling, where applicable
- [✗] Discount ID number and name recorded on the transaction
- [✗] Recurring billing
- [✗] Partial payments and installments
- [✗] Payment due dates and overdue tracking
- [✗] Penalties and interest on late payments
- [✗] Statement of account
- [✗] Printed and emailed invoices/receipts
- [✗] BIR registration/accreditation of the system if it issues invoices or receipts
- [✗] E-invoicing / electronic sales reporting if required for the client

## A3. 🤝 Sales & Customer Relationship Management (CRM)

- [✗] Customer records
- [✗] Contacts and companies
- [✗] Leads and prospects
- [✗] Sales pipeline / deal stages
- [✗] Quotations / price proposals
- [✗] Sales orders
- [✗] Customer communication history
- [✗] Follow-up reminders
- [✗] Sales targets and commissions
- [✗] Price lists and customer-specific pricing
- [✗] Customer credit limits
- [✗] Customer segmentation
- [✗] Sales reports

## A4. 📦 Inventory & Stock Management

- [✗] Product/item catalog
- [✗] Units of measure and conversions (piece, box, case)
- [✗] Product variants (size, color)
- [✗] Stock levels per location/warehouse
- [✗] Stock in / stock out
- [✗] Stock transfers between locations
- [✗] Stock adjustments with reasons
- [✗] Physical count / stock taking
- [✗] Reorder points and low-stock alerts
- [✗] Barcode / QR code support
- [✗] Batch / lot tracking
- [✗] Serial number tracking
- [✗] Expiration date tracking
- [✗] First-In-First-Out (FIFO) / First-Expired-First-Out (FEFO)
- [✗] Inventory costing (FIFO, weighted average)
- [✗] Stock movement history (stock card)
- [✗] Damaged, lost, and returned stock handling
- [✗] Negative stock prevention

## A5. 🛒 Procurement & Purchasing

- [✗] Purchase requests / requisitions
- [✗] Approval workflow for purchases
- [✗] Supplier/vendor records
- [✗] Request for quotation (RFQ)
- [✗] Supplier quotation comparison
- [✗] Purchase orders
- [✗] Receiving / delivery receipts
- [✗] Inspection and acceptance
- [✗] Three-way matching (PO, receiving, supplier invoice)
- [✗] Supplier payments
- [✗] Supplier performance tracking
- [✗] Budget checking before approval

## A6. 👥 Human Resources (HR)

- [✗] Employee records (201 files)
- [✗] Organizational chart, departments, and positions
- [✗] Employment status (probationary, regular, contractual, project-based)
- [✗] Recruitment and applicant tracking
- [✗] Onboarding checklist
- [✗] Employee documents and contracts
- [✗] Leave management (vacation, sick, maternity, paternity, solo parent, service incentive leave)
- [✗] Leave balances and accruals
- [✗] Performance evaluations
- [✗] Training records
- [✗] Disciplinary actions / incident reports
- [✗] Promotions and transfers
- [✗] Offboarding and clearance
- [✗] Certificate of employment generation
- [✗] Employee self-service portal

## A7. 💵 Payroll (Philippines)

- [✗] Salary types (monthly, daily, hourly, piece rate)
- [✗] Minimum wage per region
- [✗] Overtime pay
- [✗] Night differential
- [✗] Holiday pay (regular and special non-working days)
- [✗] Rest day pay
- [✗] Late, undertime, and absence deductions
- [✗] SSS contributions (employee and employer shares)
- [✗] PhilHealth contributions
- [✗] Pag-IBIG contributions
- [✗] Withholding tax on compensation (current TRAIN tax table)
- [✗] De minimis benefits and non-taxable allowances
- [✗] 13th month pay
- [✗] Loans and salary deductions (SSS, Pag-IBIG, company loans)
- [✗] Payslips
- [✗] Payroll register
- [✗] Bank payroll file export
- [✗] Government remittance reports (SSS, PhilHealth, Pag-IBIG)
- [✗] BIR forms (e.g. 1601-C, 2316, alphalist)
- [✗] Final pay computation
- [✗] Payroll locked after approval

## A8. ⏱️ Attendance & Timekeeping

- [✗] Time in / time out
- [✗] Biometric device integration
- [✗] Face recognition or QR attendance
- [✗] GPS/geofenced attendance for field staff
- [✗] Shift schedules and shift swapping
- [✗] Flexible time and compressed work weeks
- [✗] Overtime requests and approvals
- [✗] Official business / field work requests
- [✗] Attendance corrections with approval
- [✗] Daily time record (DTR)

## A9. 📄 Document Management & E-Signatures

- [✗] Document upload and categorization
- [✗] Document templates
- [✗] Document versioning
- [✗] Document routing and tracking
- [✗] Document approval workflow
- [✗] Incoming/outgoing communications log
- [✗] Electronic signatures
- [✗] Signature verification / QR verification of documents
- [✗] Document retention schedules
- [✗] Records disposal tracking
- [✗] Full-text search of documents

## A10. 🏢 Asset & Property Management

- [✗] Asset registry
- [✗] Asset tagging (property number, QR code)
- [✗] Asset assignment to employees/offices
- [✗] Accountability forms (e.g. property acknowledgment receipts)
- [✗] Asset transfers
- [✗] Maintenance schedules
- [✗] Repair history
- [✗] Depreciation
- [✗] Asset disposal
- [✗] Asset audit / physical inventory

## A11. 🎧 Customer Service & Ticketing

- [✗] Support tickets
- [✗] Ticket categories and priorities
- [✗] Ticket assignment
- [✗] Ticket status tracking
- [✗] Service level targets (response and resolution times)
- [✗] Escalation rules
- [✗] Customer feedback and satisfaction ratings
- [✗] Knowledge base / FAQs
- [✗] Complaint handling

## A12. 📅 Appointments, Booking & Scheduling

- [✗] Available time slots
- [✗] Online booking
- [✗] Booking confirmation
- [✗] Rescheduling and cancellation
- [✗] Cancellation policy and fees
- [✗] No-show tracking
- [✗] Reminders (SMS/email)
- [✗] Staff/resource availability
- [✗] Double-booking prevention
- [✗] Waitlists
- [✗] Deposits / down payments

## A13. 🎟️ Membership, Subscriptions & Loyalty

- [✓] ~~Membership plans~~
- [✓] ~~Membership cards / digital IDs~~
- [✓] ~~Subscription renewals~~
- [✓] ~~Expiration reminders~~
- [✓] ~~Upgrades and downgrades~~
- [✗] Loyalty points
- [✗] Rewards redemption
- [✗] Vouchers and promo codes
- [✗] Referral programs

## A14. 📣 Marketing

- [✗] Email campaigns
- [✓] ~~SMS campaigns~~
- [✓] ~~Opt-in and opt-out (unsubscribe) handling~~
- [✗] Promotions and campaigns
- [✓] ~~Customer segments~~
- [✗] Campaign performance tracking
- [✗] Social media integration
- [✗] Landing pages and lead forms

## A15. 📋 Project & Task Management

- [✗] Projects
- [✗] Tasks and subtasks
- [✗] Assignees
- [✗] Deadlines
- [✗] Milestones
- [✗] Progress tracking
- [✗] Time tracking
- [✗] Project costing and budgets
- [✗] Client billing per project

## A16. 📊 Reporting & Business Intelligence

- [✓] ~~Management dashboards~~
- [✓] ~~Key performance indicators (KPIs)~~
- [✓] ~~Daily, weekly, monthly, and annual reports~~
- [✓] ~~Comparisons (month-to-month, year-to-year)~~
- [✓] ~~Branch/department comparisons~~
- [✓] ~~Drill-down from summaries to details~~
- [✗] Scheduled reports sent by email
- [✓] ~~Data export for external analysis~~
- [✗] Government/regulatory report formats

---

# Part B — Enterprise Requirements (Large Organizations)

## B1. 🔑 Enterprise Identity & Access

- [✗] Single sign-on (SSO) — SAML, OpenID Connect, Microsoft Entra ID, Google Workspace
- [✗] Directory integration (Active Directory / LDAP)
- [✗] Automatic user provisioning and deprovisioning (SCIM)
- [✗] Enforced MFA for all users
- [✓] ~~Fine-grained permissions (per module, per action, per record)~~
- [✗] Delegation of authority (acting/temporary approvers)
- [✓] ~~Separation of duties (the person who creates cannot approve)~~
- [✓] ~~Periodic access reviews~~
- [✓] ~~Privileged access management for admins~~

## B2. 🏬 Multi-Branch, Multi-Tenant & Multi-Company

- [✓] ~~Multiple branches/locations~~
- [✓] ~~Data separated per branch, with consolidated head-office view~~
- [✗] Multi-tenancy (one system serving many client organizations)
- [✗] Strict data isolation between tenants
- [✗] Per-tenant settings, branding, and configuration
- [✗] Multiple companies/legal entities
- [✗] Inter-company transactions
- [✗] Consolidated financial reports
- [✗] Multiple currencies
- [✗] Multiple languages
- [✗] Multiple time zones

## B3. 🟢 Availability & Reliability

- [✗] Uptime target defined (e.g. 99.9%)
- [✗] Service Level Agreement (SLA)
- [✗] No single point of failure
- [✗] Redundant servers / multiple availability zones
- [✗] Database replication
- [✗] Automatic failover
- [✗] Zero-downtime deployments
- [✓] Recovery Time Objective (RTO) defined
- [✓] Recovery Point Objective (RPO) defined
- [✓] Business continuity plan
- [✗] Disaster recovery site or region
- [✓] Regular disaster recovery drills

## B4. 📈 Scalability & Performance

- [✓] Expected number of users and simultaneous users defined
- [✓] Expected data volume and growth defined
- [✓] ~~Load testing at expected and peak traffic~~
- [✗] Horizontal scaling
- [✗] Load balancing
- [✗] Read replicas
- [✗] Database partitioning/sharding, if needed
- [✗] Queue-based processing for heavy work
- [✓] ~~Caching layer~~
- [✓] ~~Performance targets (e.g. page load and API response times)~~

## B5. 🔗 Enterprise Integrations

- [✗] ERP integration (SAP, Oracle, Microsoft Dynamics, Odoo, etc.)
- [✗] Accounting software integration (QuickBooks, Xero, etc.)
- [✗] HRIS integration
- [✗] CRM integration (Salesforce, HubSpot, etc.)
- [✗] Banking integrations (payment files, bank APIs)
- [✗] Government system integrations (BIR, SSS, PhilHealth, Pag-IBIG, PhilGEPS, etc.)
- [✓] ~~Legacy system integration~~
- [✓] ~~Data exchange formats (CSV, Excel, XML, JSON, EDI)~~
- [✓] ~~Integration error handling and retry~~
- [✓] ~~Integration monitoring~~
- [✗] Middleware / message queue / event bus, if needed
- [✓] ~~Public API for partners~~
- [✓] ~~API documentation for integrators~~

## B6. 🗂️ Data Governance

- [✓] Data ownership defined per module
- [✓] Data classification (public, internal, confidential, restricted)
- [✓] ~~Master data management (single source of truth for customers, products, employees)~~
- [✓] ~~Data quality checks~~
- [✓] Data retention schedules per record type
- [✗] Legal hold (preventing deletion during disputes or investigations)
- [✓] Data residency (where data is allowed to be stored)
- [✗] Data warehouse / reporting database separated from operations
- [✓] ~~Data lineage (where reports get their data)~~

## B7. 🔄 Change & Release Management

- [✓] Change request process
- [✗] Change approval board / approvers
- [✗] Release schedule
- [✓] Release notes
- [✓] Separate development, testing, staging, and production environments
- [✓] User Acceptance Testing sign-off before production
- [✓] Rollback plan for every release
- [✓] Maintenance windows communicated in advance
- [✓] ~~Configuration management (tracked settings changes)~~

## B8. 🧑‍💼 Enterprise Support & Operations

- [✗] Help desk / service desk
- [✗] Support tiers (Level 1, 2, 3)
- [✗] Support hours and on-call schedule
- [✓] Incident management process
- [✗] Problem management (finding root causes)
- [✗] Status page for outages
- [✓] User training programs
- [✓] Administrator training
- [✓] Operations runbooks
- [✓] Knowledge transfer documentation

## B9. 📑 Procurement & Vendor Requirements (When Selling to Enterprises or Government)

- [✗] Security questionnaires answered
- [✗] Compliance certifications, if required (ISO 27001, SOC 2, etc.)
- [✓] Data processing agreement
- [✗] Non-disclosure agreement
- [✗] Service level agreement
- [✗] Source code escrow, if required
- [✗] Liability and indemnity terms
- [✗] Insurance requirements
- [✗] Business registration documents (DTI/SEC, BIR, Mayor's permit)
- [✗] Government bidding eligibility documents (PhilGEPS registration), if applicable

## B10. 🏛️ Corporate Governance & Internal Controls

- [✗] Internal control requirements
- [✗] Approval limits (amount thresholds per role)
- [✓] ~~Segregation of duties~~
- [✓] ~~Complete audit trail (who, what, when, before/after values)~~
- [✗] Internal audit access (read-only auditor role)
- [✗] External auditor reports
- [✗] Risk register
- [✗] Policy acknowledgment by employees
- [✗] Whistleblower / anonymous reporting channel

---

# Part C — Industry-Specific Requirements

## C1. 🏪 Retail & Point of Sale (POS)

- [✗] Fast checkout screen
- [✗] Barcode scanner integration
- [✗] Receipt printer integration (thermal printers)
- [✗] Cash drawer integration
- [✗] Multiple payment methods (cash, card, e-wallet, QR Ph)
- [✗] Split payments
- [✗] Change computation
- [✗] Discounts (senior citizen, PWD, solo parent, promos)
- [✗] Returns, refunds, and exchanges
- [✗] Void transactions with supervisor approval
- [✗] Cashier shifts, cash count, and end-of-day reports
- [✗] X-reading and Z-reading reports
- [✗] Accumulated sales counter that cannot be reset
- [✗] BIR accreditation / Permit to Use for POS machines
- [✗] Offline mode when internet is down
- [✗] Customer display
- [✗] Price checker
- [✗] Multi-store support

## C2. 🛍️ E-Commerce & Online Marketplace

- [✗] Product listings with images and descriptions
- [✗] Product categories and search
- [✗] Shopping cart
- [✗] Checkout
- [✗] Shipping fee computation
- [✗] Courier integration and tracking
- [✗] Order status tracking
- [✗] Cash on delivery (COD)
- [✗] Online payment gateway
- [✗] Order cancellation and returns/refunds
- [✗] Product reviews and ratings (genuine customers only)
- [✗] Seller accounts and seller dashboards (marketplace)
- [✗] Seller commissions and payouts (marketplace)
- [✗] Seller verification
- [✗] Wishlist
- [✗] Abandoned cart reminders
- [✗] Vouchers and flash sales
- [✗] Clear seller and business information shown (consumer protection / Internet Transactions Act)
- [✗] Terms of sale, return policy, and privacy policy

## C3. 🍽️ Restaurants & Food Service

- [✗] Menu management
- [✗] Menu modifiers and add-ons
- [✗] Table management and floor plan
- [✗] Order taking (waiter app / tablet)
- [✗] Kitchen display / kitchen order tickets
- [✗] Dine-in, take-out, and delivery orders
- [✗] Food delivery platform integration
- [✗] Bill splitting
- [✗] Service charge handling
- [✗] Recipe and ingredient inventory (bill of materials)
- [✗] Food cost tracking
- [✗] Wastage tracking
- [✗] Reservations
- [✗] Senior citizen/PWD discount per diner

## C4. 🏨 Hotels, Resorts & Hospitality

- [✗] Room types and room inventory
- [✗] Room rates and seasonal pricing
- [✗] Reservations and booking engine
- [✗] Online travel agency integration (channel manager)
- [✗] Check-in and check-out
- [✗] Guest records
- [✗] Folio / guest billing
- [✗] Deposits and advance payments
- [✗] Housekeeping status (clean, dirty, out of order)
- [✗] Room service and amenity charges
- [✗] Night audit
- [✗] Event and function room booking
- [✗] Tourism accreditation requirements (DOT), if applicable

## C5. ✈️ Travel & Tourism

- [✗] Tour packages
- [✗] Itineraries
- [✗] Booking and passenger details
- [✗] Ticketing
- [✗] Visa and travel document checklists
- [✗] Supplier bookings (hotels, transport, guides)
- [✗] Group bookings
- [✗] Payment schedules and installments
- [✗] Cancellation and refund rules
- [✗] Tour guide scheduling

## C6. 🏦 Banking & Financial Services

- [✗] Customer accounts
- [✗] Know Your Customer (KYC) verification
- [✗] Customer due diligence and risk rating
- [✗] Deposits and withdrawals
- [✗] Fund transfers
- [✗] Interest computation
- [✗] Account statements
- [✗] Double-entry accounting with strict reconciliation
- [✗] Transaction limits
- [✗] Anti-money laundering (AML) monitoring
- [✗] Covered and suspicious transaction reporting (AMLC)
- [✗] Sanctions and watchlist screening
- [✗] Fraud detection
- [✗] Maker-checker (two-person approval)
- [✗] End-of-day processing
- [✗] BSP regulatory reports
- [✗] BSP IT risk management and cybersecurity requirements
- [✗] Financial consumer protection requirements
- [✗] Very strong audit trails and record retention

## C7. 💳 Lending, Microfinance & Financing

- [✗] Loan products and terms
- [✗] Loan applications
- [✗] Credit evaluation / scoring
- [✗] Loan approval workflow
- [✗] Loan release / disbursement
- [✗] Amortization schedules
- [✗] Interest computation (diminishing balance, add-on, etc.)
- [✗] Payment collection
- [✗] Penalties for late payments
- [✗] Loan restructuring
- [✗] Collateral tracking
- [✗] Co-makers / guarantors
- [✗] Aging of receivables / past-due reports
- [✗] Full disclosure of loan terms (Truth in Lending)
- [✗] Fair debt collection practices
- [✗] SEC registration and reporting for lending/financing companies, if applicable

## C8. 🤲 Cooperatives

- [✓] ~~Member records~~
- [✗] Share capital
- [✓] ~~Savings deposits~~
- [✗] Loans to members
- [✗] Dividends / interest on share capital
- [✗] Patronage refunds
- [✗] General assembly and voting records
- [✗] Board and committee records
- [✗] Cooperative Development Authority (CDA) reports

## C9. 🛡️ Insurance

- [✓] ~~Insurance products and plans~~
- [✗] Quotations
- [✓] ~~Policy issuance~~
- [✗] Premium computation and billing
- [✓] ~~Policy renewals~~
- [✗] Beneficiaries
- [✗] Claims filing
- [✗] Claims evaluation and approval
- [✗] Agent/broker commissions
- [✓] ~~Underwriting rules~~
- [✗] Insurance Commission requirements

## C10. 📲 E-Wallets, Remittance & Fintech

- [✗] User wallet accounts
- [✗] Cash-in and cash-out
- [✗] Peer-to-peer transfers
- [✗] QR payments (QR Ph)
- [✗] Bill payments
- [✗] Remittance sending and claiming
- [✗] Transaction limits by verification level
- [✗] eKYC (ID and selfie verification)
- [✗] Real-time fraud monitoring
- [✗] Account takeover protection
- [✗] AML monitoring and reporting
- [✗] Ledger that always balances
- [✗] BSP licensing requirements (EMI, remittance, payment operator), if applicable

## C11. 💍 Pawnshops

- [✗] Pawn tickets
- [✗] Item appraisal
- [✗] Item photos and descriptions
- [✗] Loan amount and interest
- [✗] Maturity and expiry dates
- [✗] Renewals and redemptions
- [✗] Auction of unredeemed items
- [✗] Vault inventory
- [✗] BSP pawnshop regulations

## C12. 🏥 Hospitals & Clinics

- [✗] Patient registration
- [✗] Patient records / Electronic Medical Records (EMR)
- [✗] Appointments and queueing
- [✗] Consultations and doctor's notes
- [✗] Vital signs
- [✗] Diagnoses (ICD-10 codes)
- [✗] Prescriptions
- [✗] Laboratory and imaging requests
- [✗] Admission, discharge, and transfer
- [✗] Bed/room management
- [✗] Nursing notes
- [✗] Medication administration records
- [✗] Billing and patient statements
- [✗] PhilHealth membership verification and eClaims
- [✗] HMO accreditation and claims
- [✗] Doctor professional fees
- [✗] Medical certificates
- [✗] Consent forms
- [✗] Strict access control (only the care team sees patient records)
- [✗] Access logging for every patient record view
- [✗] DOH licensing and reporting requirements
- [✗] Health data handled as sensitive personal information under the Data Privacy Act

## C13. 🏫 School Clinics & Workplace Health

- [✗] Student/employee health records
- [✗] Clinic visits and complaints
- [✗] Medicine dispensing and inventory
- [✗] Medical clearances
- [✗] Annual physical exam records
- [✗] Vaccination records
- [✗] Referrals to hospitals
- [✗] Parent/guardian notifications (for minors)
- [✗] Strict confidentiality of health records

## C14. 💊 Pharmacies & Drugstores

- [✗] Medicine catalog (generic and brand names)
- [✗] Prescription recording
- [✗] Prescription-only medicine checks
- [✗] Dangerous drugs records and special prescriptions, if applicable
- [✗] Batch and expiration tracking
- [✗] FEFO stock rotation
- [✗] Near-expiry alerts
- [✗] Senior citizen/PWD medicine discounts and purchase booklets
- [✗] FDA licensing requirements
- [✗] Generic substitution records

## C15. 🔬 Laboratories & Diagnostic Centers

- [✗] Test catalog and price list
- [✗] Test requests
- [✗] Specimen collection and labeling
- [✗] Specimen tracking
- [✗] Result entry
- [✗] Reference ranges and abnormal flags
- [✗] Result validation by authorized personnel
- [✗] Result release (printed and online)
- [✗] Laboratory machine integration
- [✗] Quality control records

## C16. 🦷 Dental Clinics

- [✗] Patient records
- [✗] Dental chart (tooth chart)
- [✗] Treatment plans
- [✗] Procedures performed per tooth
- [✗] X-ray image storage
- [✗] Appointments
- [✗] Installment payments for treatments
- [✗] Consent forms

## C17. 🐾 Veterinary Clinics

- [✗] Pet owner records
- [✗] Pet records (species, breed, age)
- [✗] Vaccination schedules and reminders
- [✗] Consultations and treatments
- [✗] Prescriptions
- [✗] Grooming and boarding services
- [✗] Billing

## C18. 🎓 Schools & Universities

- [✗] Student records
- [✗] Admission and application
- [✗] Enrollment
- [✗] Sections and class schedules
- [✗] Subjects/courses and curriculum
- [✗] Prerequisites
- [✗] Teacher/faculty loading
- [✗] Room scheduling
- [✗] Grading system
- [✗] Grade encoding and approval
- [✗] Report cards / transcript of records
- [✗] Attendance
- [✗] Tuition and fees assessment
- [✗] Scholarships and discounts
- [✗] Student accounts and payments
- [✗] Clearances
- [✗] Graduation requirements and evaluation
- [✗] Certificates and diplomas
- [✗] Parent portal
- [✗] Learning management system
- [✗] Library integration
- [✗] Alumni records
- [✗] DepEd, CHED, or TESDA reporting formats, as applicable
- [✗] Learner Reference Number (LRN) handling, if basic education
- [✗] Extra protection for minors' data
- [✗] Child protection policies

## C19. 📚 Training Centers & Online Learning

- [✗] Courses and modules
- [✗] Lessons (video, documents)
- [✗] Quizzes and exams
- [✗] Question banks
- [✗] Exam randomization and timers
- [✗] Anti-cheating measures
- [✗] Progress tracking
- [✗] Certificates of completion
- [✗] Instructor management
- [✗] Course enrollment and payment
- [✗] Discussion forums

## C20. 📖 Libraries

- [✗] Book catalog
- [✗] Barcode/RFID for books
- [✗] Borrowing and returns
- [✗] Due dates and overdue fines
- [✗] Reservations
- [✗] Member/patron records
- [✗] Digital resources
- [✗] Inventory and weeding

## C21. 🏛️ Government Offices & Agencies

- [✗] Citizen/client services and online applications
- [✗] Document tracking system
- [✗] Citizen's Charter processing times (Ease of Doing Business / Anti-Red Tape Act)
- [✗] Queueing system
- [✗] Permits and licenses
- [✗] Official forms in the prescribed formats
- [✗] Signatories and approval chains
- [✗] Procurement following the government procurement law and PhilGEPS
- [✗] Annual Procurement Plan and Project Procurement Management Plan
- [✗] Supply and property management forms (e.g. RIS, PAR, ICS)
- [✗] Budget, obligations, and disbursements tracking
- [✗] COA audit requirements
- [✗] Records management following National Archives rules
- [✗] Freedom of Information requests, if applicable
- [✗] Accessibility for persons with disabilities
- [✗] Data Privacy Act compliance (government also handles large amounts of personal data)
- [✗] Data sharing agreements with other agencies

## C22. 🏘️ Local Government Units (LGU) & Barangays

- [✗] Resident/household profiling
- [✗] Barangay clearance and certificates
- [✗] Business permits and licensing
- [✗] Real property tax
- [✗] Community tax certificates (cedula)
- [✗] Blotter records
- [✗] Health center records
- [✗] Disaster risk reduction (evacuation, relief distribution)
- [✗] Social services (senior citizens, PWD, solo parents, 4Ps beneficiaries)
- [✗] Ordinances and resolutions
- [✗] Projects and infrastructure tracking

## C23. 🏠 Real Estate & Property Management

- [✗] Property listings
- [✗] Units/lots inventory
- [✗] Reservations and reservation fees
- [✗] Sales contracts
- [✗] Payment schedules and amortizations
- [✗] Buyer protections for installment sales (Maceda Law)
- [✗] Title and document tracking
- [✗] Broker/agent commissions
- [✗] Licensed real estate practitioner requirements
- [✗] Tenant management
- [✗] Lease contracts
- [✗] Rent billing
- [✗] Association dues
- [✗] Maintenance requests

## C24. 🛏️ Dormitories, Boarding Houses & Rentals

- [✗] Rooms and bed spaces
- [✗] Room availability
- [✗] Tenant/boarder registration
- [✗] Tenant documents and IDs
- [✗] Emergency contacts
- [✗] Guardian/parent contacts (for student boarders)
- [✗] Monthly rent billing
- [✗] Utility billing (electricity, water submetering)
- [✗] Deposits and advance payments
- [✗] Payment tracking and overdue alerts
- [✗] House rules and acknowledgment
- [✗] Curfew and visitor logs
- [✗] Maintenance requests
- [✗] Move-in and move-out inspections
- [✗] Rent increase limits, if covered by rent control rules

## C25. 🏗️ Construction

- [✗] Projects and sites
- [✗] Bill of quantities
- [✗] Cost estimates
- [✗] Project schedules (Gantt charts)
- [✗] Progress billing
- [✗] Retention money
- [✗] Change orders
- [✗] Materials tracking per site
- [✗] Equipment tracking
- [✗] Subcontractors
- [✗] Daily site reports
- [✗] Safety reports and incidents
- [✗] Manpower and daily labor payroll
- [✗] Contractor license requirements (PCAB), if applicable

## C26. 🏭 Manufacturing

- [✗] Bill of materials (BOM)
- [✗] Production planning
- [✗] Work orders / job orders
- [✗] Raw materials inventory
- [✗] Work-in-progress tracking
- [✗] Finished goods inventory
- [✗] Production costing
- [✗] Machine scheduling
- [✗] Machine maintenance
- [✗] Quality control and inspection
- [✗] Defects and rework
- [✗] Batch/lot traceability
- [✗] Production reports and yield
- [✗] Machine/IoT integration, if needed
- [✗] Workplace safety records

## C27. 🚚 Warehousing, Logistics & Courier

- [✗] Warehouse locations (zones, racks, bins)
- [✗] Receiving
- [✗] Put-away
- [✗] Picking and packing
- [✗] Dispatch
- [✗] Shipment creation
- [✗] Waybills and labels
- [✗] Shipment tracking
- [✗] Route planning
- [✗] Rider/driver assignment
- [✗] Proof of delivery (photo, signature)
- [✗] Failed delivery and return-to-sender handling
- [✗] COD remittance tracking
- [✗] Delivery rates by area and weight

## C28. 🚌 Transportation & Fleet Management

- [✗] Vehicle records
- [✗] Driver records and licenses
- [✗] Trip scheduling
- [✗] GPS tracking
- [✗] Fuel tracking
- [✗] Maintenance schedules
- [✗] Vehicle registration and insurance expiry alerts
- [✗] Driver violations and incidents
- [✗] Fare computation
- [✗] Ticketing and seat reservations
- [✗] Franchise and regulatory requirements (LTFRB, LTO), if applicable

## C29. 🌾 Agriculture, Fisheries & Aquaculture

- [✗] Farmer/fisherfolk records
- [✗] Farm/plot records and mapping
- [✗] Crop or stock cycles (planting, growing, harvesting)
- [✗] Inputs tracking (seeds, fertilizer, feeds)
- [✗] Harvest records and yields
- [✗] Pest and disease monitoring
- [✗] Weather data integration
- [✗] Image-based disease detection, if applicable
- [✗] Offline data collection in the field
- [✗] Buyer/trader records and pricing
- [✗] Cooperative/association records
- [✗] Government program beneficiaries and subsidies
- [✗] DA, BFAR, or other agency reporting formats, if applicable

## C30. ⚡ Utilities (Water, Electricity, Internet)

- [✗] Customer accounts
- [✗] Service connection applications
- [✗] Meter records
- [✗] Meter reading (mobile, offline)
- [✗] Consumption computation
- [✗] Tiered/rate-based billing
- [✗] Bill printing and delivery
- [✗] Payments and collection
- [✗] Disconnection and reconnection
- [✗] Service interruption notices
- [✗] Complaints and repair requests
- [✗] Regulatory reporting (e.g. ERC, LWUA, NTC), if applicable

## C31. 📡 Telecommunications & Internet Service Providers

- [✗] Subscriber accounts
- [✗] Plans and packages
- [✗] Installation scheduling
- [✗] Recurring billing
- [✗] Network/device provisioning
- [✗] Usage tracking
- [✗] Outage tracking
- [✗] Technical support tickets
- [✗] SIM registration requirements, if applicable
- [✗] NTC regulatory requirements

## C32. 🎭 Events, Ticketing & Entertainment

- [✗] Events and schedules
- [✗] Venue and seating maps
- [✗] Ticket types and pricing
- [✗] Online ticket sales
- [✗] QR code tickets
- [✗] Ticket scanning at entry
- [✗] Duplicate ticket prevention
- [✗] Refunds and transfers
- [✗] Attendee lists
- [✗] Event registration forms

## C33. 🏋️ Gyms, Fitness & Sports

- [✗] Memberships and plans
- [✗] Check-in (QR, RFID, face)
- [✗] Class schedules and booking
- [✗] Trainer assignments
- [✗] Personal training sessions
- [✗] Member progress tracking
- [✗] Facility/court reservations
- [✗] Waivers and health declarations

## C34. 💇 Salons, Spas & Personal Services

- [✗] Services and price list
- [✗] Appointments
- [✗] Staff schedules
- [✗] Staff commissions
- [✗] Customer history and preferences
- [✗] Packages and service bundles
- [✗] Product sales
- [✗] Gift certificates

## C35. 🚗 Automotive (Repair Shops & Dealerships)

- [✗] Customer and vehicle records
- [✗] Job orders
- [✗] Vehicle inspection checklists
- [✗] Parts inventory
- [✗] Labor charges
- [✗] Repair estimates and approvals
- [✗] Service history per vehicle
- [✗] Preventive maintenance reminders
- [✗] Vehicle sales and financing (dealerships)
- [✗] Warranty claims

## C36. ⚖️ Legal Services & Law Firms

- [✗] Client records
- [✗] Case/matter management
- [✗] Court hearing schedules
- [✗] Deadlines and reminders
- [✗] Document management
- [✗] Time tracking and billable hours
- [✗] Client billing and retainers
- [✗] Conflict-of-interest checks
- [✗] Strict confidentiality and access control

## C37. 🧮 Accounting, Bookkeeping & Consulting Firms

- [✗] Client records
- [✗] Engagements/projects
- [✗] Tax filing deadlines and reminders
- [✗] Document collection from clients
- [✗] Time tracking
- [✗] Billing and retainers
- [✗] Working papers
- [✗] Client portal

## C38. 🎧 BPO & Call Centers

- [✗] Agent records
- [✗] Shift scheduling and workforce management
- [✗] Call/ticket logging
- [✗] Call recording storage, if applicable
- [✗] Quality assurance scoring
- [✗] KPIs (handling time, resolution rate, satisfaction)
- [✗] Client account separation
- [✗] Strict data protection for client customer data
- [✗] Client security and compliance requirements (often international standards)

## C39. 🧑‍🔧 Staffing, Recruitment & Manpower Agencies

- [✗] Applicant database
- [✗] Job orders from clients
- [✗] Applicant screening and interviews
- [✗] Deployment to clients
- [✗] Contracts
- [✗] Billing to clients
- [✗] Payroll of deployed workers
- [✗] DOLE requirements for contractors/subcontractors, if applicable
- [✗] Overseas recruitment requirements (DMW), if applicable

## C40. 💂 Security Agencies

- [✗] Guard records and licenses
- [✗] Guard deployment to posts
- [✗] Shift schedules
- [✗] Guard tour/patrol checkpoints
- [✗] Incident reports
- [✗] Visitor logs
- [✗] Firearm records, if applicable
- [✗] License expiry alerts

## C41. 🧺 Laundry, Repair, Printing & Small Services

- [✗] Job orders / claim stubs
- [✗] Service pricing (per kilo, per item, per page)
- [✗] Job status tracking
- [✗] Ready-for-pickup notifications
- [✗] Payments and balances
- [✗] Unclaimed items tracking

## C42. ⛪ Non-Profits, NGOs & Religious Organizations

- [✗] Member records
- [✗] Donor records
- [✗] Donations and pledges
- [✗] Donation receipts
- [✗] Fund accounting (restricted vs. unrestricted funds)
- [✗] Programs and beneficiaries
- [✗] Volunteer management
- [✗] Events and activities
- [✗] Grant tracking and reporting
- [✗] Sacramental or ministry records, if religious
- [✗] Transparency reports

## C43. ⚱️ Funeral Services & Memorial Parks

- [✗] Service packages
- [✗] Contracts
- [✗] Plan holders and installment payments
- [✗] Lot/niche inventory and mapping
- [✗] Interment records
- [✗] Death certificate and document tracking

## C44. 🅿️ Parking & Facility Access

- [✗] Vehicle entry and exit logging
- [✗] Plate number recognition, if applicable
- [✗] Parking fee computation
- [✗] Monthly parking passes
- [✗] Slot availability
- [✗] Lost ticket handling

## C45. ⛏️ Mining, Energy & Heavy Industry

- [✗] Equipment and heavy machinery tracking
- [✗] Production output tracking
- [✗] Environmental monitoring and compliance reports
- [✗] Health and safety incidents
- [✗] Permits and license tracking
- [✗] Shift and crew management
- [✗] Regulatory reporting to the relevant agencies

## C46. 🎰 Gaming & Lottery

- [✗] Player accounts and age verification
- [✗] Responsible gaming controls (limits, self-exclusion)
- [✗] Wallet/ledger that always balances
- [✗] Game results integrity and audit
- [✗] AML monitoring
- [✗] PAGCOR licensing and regulatory requirements

## C47. 💻 Software, SaaS & Digital Platforms

- [✗] Subscription plans and pricing tiers
- [✗] Free trials
- [✗] Recurring billing
- [✗] Usage-based billing, if applicable
- [✗] Feature limits per plan
- [✗] Tenant/workspace management
- [✗] Team members and invitations
- [✗] Onboarding flows
- [✗] In-app notifications and announcements
- [✗] Public API and API keys
- [✗] Webhooks for customers
- [✗] Status page
- [✗] Customer data export
- [✗] Account deletion

## C48. 💬 Social, Community & Content Platforms

- [✗] User profiles
- [✗] Posts, comments, and reactions
- [✗] Following/friends
- [✗] Messaging
- [✗] Content moderation
- [✗] Reporting of abusive content
- [✗] Blocking and muting users
- [✗] Spam and bot prevention
- [✗] Age restrictions and minor protection
- [✗] Takedown process for illegal or infringing content
- [✗] Community guidelines

## C49. 🧑‍💻 Gig & On-Demand Service Platforms

- [✗] Customer and provider accounts
- [✗] Provider verification
- [✗] Service requests and matching
- [✗] Real-time location tracking
- [✗] Pricing and surge rules
- [✗] In-app payments and provider payouts
- [✗] Ratings for both sides
- [✗] Dispute handling
- [✗] Safety features (emergency button, trip sharing)

---

# Part D — Laws & Regulations (Philippines)

Check which ones apply to the client's business. Always verify the current version.

## D1. 🔒 Data, Privacy & Cyber Laws

- [✓] Data Privacy Act of 2012 (RA 10173) and NPC rules
- [✗] Cybercrime Prevention Act of 2012 (RA 10175)
- [✗] Electronic Commerce Act of 2000 (RA 8792) — validity of electronic documents and signatures
- [✗] Internet Transactions Act of 2023 (RA 11967) — online sellers and platforms
- [✗] SIM Registration Act (RA 11934), if handling SIM/telecom data
- [✗] Philippine Identification System Act (RA 11055), if using PhilSys/National ID verification

## D2. 💰 Tax & Business Registration

- [✗] National Internal Revenue Code and BIR regulations
- [✗] Ease of Paying Taxes Act (RA 11976) — invoicing rules
- [✗] BIR registration/accreditation of computerized accounting systems, POS, and invoicing systems
- [✗] BIR electronic invoicing / sales reporting, if required for the taxpayer
- [✗] Books of accounts requirements (manual, loose-leaf, or computerized)
- [✗] Business registration (DTI, SEC, or CDA) and local permits

## D3. 👷 Labor & Employment

- [✗] Labor Code of the Philippines and DOLE rules
- [✗] Regional minimum wage orders
- [✗] 13th Month Pay Law (PD 851)
- [✗] Social Security Act (RA 11199)
- [✗] Universal Health Care Act (RA 11223) / PhilHealth rules
- [✗] Pag-IBIG Fund Law (RA 9679)
- [✗] Expanded Maternity Leave Law (RA 11210)
- [✗] Paternity leave, solo parent leave, and other statutory leaves
- [✗] Telecommuting Act (RA 11165), if work-from-home
- [✗] Occupational Safety and Health Standards (RA 11058)

## D4. 🛒 Consumer Protection & Discounts

- [✗] Consumer Act of the Philippines (RA 7394)
- [✗] Expanded Senior Citizens Act (RA 9994) — 20% discount and VAT exemption
- [✗] Magna Carta for Persons with Disability (RA 7277, as amended) — 20% discount and VAT exemption
- [✗] Expanded Solo Parents Welfare Act (RA 11861) — benefits and discounts, where applicable
- [✗] Price Act (RA 7581), for basic necessities
- [✗] Financial Products and Services Consumer Protection Act (RA 11765), for financial services

## D5. 🏦 Finance & Banking

- [✗] Anti-Money Laundering Act (RA 9160, as amended) and AMLC rules
- [✗] BSP regulations (banks, e-money issuers, remittance, pawnshops, payment systems)
- [✗] BSP IT risk management and cybersecurity circulars
- [✗] National Payment Systems Act (RA 11127)
- [✗] Lending Company Regulation Act (RA 9474) and Financing Company Act (RA 8556), with SEC rules
- [✗] Truth in Lending Act (RA 3765)
- [✓] Philippine Cooperative Code (RA 9520) and CDA rules
- [✗] Insurance Code and Insurance Commission rules

## D6. 🏛️ Government

- [✗] New Government Procurement Act (RA 12009) and its implementing rules
- [✗] PhilGEPS requirements
- [✗] COA rules and audit requirements
- [✗] Ease of Doing Business and Efficient Government Service Delivery Act (RA 11032)
- [✗] National Archives of the Philippines Act (RA 9470) — records management
- [✗] DICT standards and guidelines for government ICT
- [✗] Freedom of Information rules, where applicable

## D7. 🏥 Health, Education & Other Sectors

- [✗] DOH licensing and health information standards
- [✗] PhilHealth eClaims and reporting
- [✗] FDA rules (pharmacies, food, drugs, cosmetics)
- [✗] Comprehensive Dangerous Drugs Act (RA 9165) recordkeeping, if handling dangerous drugs
- [✗] DepEd, CHED, and TESDA rules and reporting formats
- [✗] Child protection rules for systems used by minors
- [✗] Real Estate Service Act (RA 9646) and Maceda Law (RA 6552)
- [✗] Rent control rules, where applicable
- [✗] Tourism Act (RA 9593) and DOT accreditation
- [✗] Philippine Fisheries Code (RA 8550, as amended by RA 10654) and DA/BFAR rules
- [✗] LTFRB/LTO rules for transport
- [✗] ERC, NTC, LWUA, and other sector regulators
- [✗] PAGCOR rules for gaming
- [✓] ~~Intellectual Property Code (RA 8293) — software licensing, content, trademarks~~

---

# Part E — International Standards & Laws (If the Client Requires Them)

## E1. 🌍 Privacy Laws in Other Countries

- [✗] GDPR (European Union users)
- [✗] UK GDPR (United Kingdom users)
- [✗] CCPA/CPRA (California users)
- [✗] Other countries' privacy laws where users are located
- [✓] Cross-border data transfer requirements

## E2. 🛡️ Security & Management Standards

- [✗] ISO/IEC 27001 — information security management
- [✗] SOC 2 — security, availability, confidentiality controls (common for SaaS/BPO clients)
- [✗] PCI DSS — card payment data
- [✗] NIST Cybersecurity Framework
- [✗] CIS Controls / CIS Benchmarks for servers
- [✓] OWASP ASVS — application security verification
- [✗] ISO 22301 — business continuity
- [✗] ISO 9001 — quality management
- [✗] ITIL — IT service management

## E3. 🏥 Industry Standards

- [✗] HIPAA (US health data)
- [✗] HL7 / FHIR (health data exchange)
- [✗] ICD-10 / ICD-11 (diagnosis codes)
- [✗] ISO 20022 (financial messaging)
- [✗] SOX (US public company financial controls)
- [✗] IFRS / PFRS (accounting standards)
- [✗] EDI standards (supply chain document exchange)
- [✗] GS1 barcodes (retail products)

## E4. ♿ Accessibility Standards

- [✓] ~~WCAG 2.2 (web accessibility guidelines)~~
- [✗] Accessibility requirements for government or public-facing systems

---

# Part F — Enterprise & Regulated-Industry Security Additions

Extra security measures on top of `security-audit-checklist.md` for enterprises, banks, hospitals, government, and other high-risk systems.

## F1. 🧭 Security Governance

- [✗] Information security policy
- [✗] Acceptable use policy
- [✗] Data classification policy
- [✗] Access control policy
- [✓] Incident response policy
- [✗] Assigned security officer / CISO
- [✓] Data Protection Officer (DPO)
- [✗] Risk assessments
- [✓] Privacy Impact Assessments
- [✗] Threat modeling for major features
- [✗] Security requirements in every project

## F2. 🛰️ Security Operations

- [✗] Security Operations Center (SOC) or managed security provider
- [✗] Centralized log collection (SIEM)
- [✗] 24/7 alerting and on-call security response
- [✗] Intrusion detection/prevention (IDS/IPS)
- [✗] Web Application Firewall (WAF)
- [✗] Endpoint detection and response (EDR) on servers and company devices
- [✓] ~~Vulnerability scanning on a schedule~~
- [✓] Patch management process with deadlines
- [✗] Regular professional penetration tests
- [✗] Bug bounty or vulnerability disclosure program

## F3. 🌐 Network & Infrastructure Security

- [✗] Network segmentation (separating public, app, and database layers)
- [✓] Private networks for databases and internal services
- [✗] VPN or zero-trust access for internal systems
- [✗] Bastion/jump host for server access
- [✗] DDoS protection
- [✓] Hardened server configurations (CIS Benchmarks)
- [✗] Infrastructure as code with security review

## F4. 🔐 Data Protection

- [✓] Encryption at rest for databases, files, and backups
- [✗] Encryption in transit everywhere, including internal traffic
- [✗] Key management service or hardware security module (HSM)
- [✗] Encryption key rotation
- [✓] ~~Data masking in non-production environments~~
- [✗] Data Loss Prevention (DLP) controls
- [✗] Tokenization of highly sensitive data (e.g. card numbers)
- [✓] ~~Secure data disposal~~

## F5. 🧍 Insider Threat & People Security

- [✗] Background checks for staff with sensitive access
- [✓] ~~Least-privilege access for employees~~
- [✓] ~~Immediate access removal when staff leave~~
- [✓] ~~Monitoring of privileged user activity~~
- [✗] Security awareness training for all staff
- [✗] Phishing simulation exercises
- [✗] Clean desk and screen lock policies
- [✓] Confidentiality agreements

## F6. 🤝 Third-Party & Vendor Risk

- [✗] Vendor security assessments
- [✓] Data processing agreements with vendors
- [✓] List of all third-party services that receive data
- [✗] Vendor access reviewed and removed when no longer needed
- [✗] Software bill of materials (SBOM), if required

## F7. 🏢 Physical & Device Security

- [✓] ~~Secure data center or reputable cloud provider~~
- [✗] Physical access control for server rooms
- [✗] Company device management (MDM)
- [✓] Full-disk encryption on laptops
- [✗] Remote wipe for lost devices
- [✗] Rules for personal devices (BYOD)

## F8. 📋 Compliance Evidence & Audits

- [✗] Evidence collected for each control (logs, screenshots, policies)
- [✗] Internal security audits
- [✗] External audits/certifications, if required
- [✗] Regulatory reporting of security incidents within required deadlines
- [✗] Records of security training, access reviews, and tests

---

# Quick Rule

**Do not automatically implement everything on this list.**

For every item, ask:

> **"Does this client's business, size, and industry need this?"**

If **yes** → include it in the requirements/design and implement it at the appropriate stage.

If **no** → don't add unnecessary complexity.

Industry requirements come mainly from talking to the client and checking that industry's current laws. This checklist helps make sure nothing important is forgotten.
