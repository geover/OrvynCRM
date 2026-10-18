/**
 * The Privacy Policy and the Terms of Use. They describe how OrvynCRM really works (a CRM where every company has its own
 * workspace and database, with e-mail, SMS and integrations). Edit the words here; the pages only lay them out.
 *
 * Have them reviewed by a lawyer who knows your country before launch, and fill in `legal` below.
 */
import { remoteSettings } from '../lib/remote';
import { site } from './site';

const remote = await remoteSettings();

export const legal = {
    /** The company that offers the service. Replace with the registered name once there is one. */
    entity: remote.legal_entity || site.name,
    /** Printed in the contact section when set (env PUBLIC_BUSINESS_ADDRESS). */
    address: String(remote.business_address || import.meta.env.PUBLIC_BUSINESS_ADDRESS || '').split('\\n').join('\n').trim(),
    effective: remote.legal_effective || 'October 13, 2026',
    updated: remote.legal_updated || 'October 13, 2026',
};

export type Block = { p: string } | { ul: string[] };
export type Section = { id: string; title: string; blocks: Block[]; contact?: boolean };

const E = legal.entity;
const p = (text: string): Block => ({ p: text });
const ul = (...items: string[]): Block => ({ ul: items });

export const privacy: { intro: string; sections: Section[] } = {
    intro: `This Privacy Policy explains how ${E} ("we", "us") collects, uses, shares and protects personal information when you visit our website, use the ${E} service (the "Service"), or contact us. It also explains your choices and rights.`,
    sections: [
        { id: 'who-we-are', title: 'Who we are and our two roles', blocks: [
            p(`${E} is a customer relationship management (CRM) platform. Each company that uses the Service (a "Customer") gets its own workspace with its own database, its own people and its own data model.`),
            p('We act in two roles:'),
            ul(
                'As a controller, for information about visitors to our website, people who ask us about the Service, and the administrators and users of Customer workspaces (their account details and how they use the Service).',
                'As a processor (or "service provider"), for the contacts, records, notes, files, e-mails and text messages that a Customer stores in its workspace. The Customer decides why and how that information is used and is responsible for having a lawful basis to use it. We handle it only on the Customer\'s instructions and to provide the Service.',
            ),
            p('If you are a contact of one of our Customers and have a question about your information, please contact that Customer first. We will help them respond to you.'),
        ] },
        { id: 'information-we-collect', title: 'Information we collect', blocks: [
            p('The information we collect depends on how you deal with us.'),
            ul(
                'Website and enquiries: your name, work e-mail address, company, and the message you send when you request a demo or contact us.',
                'Accounts: name, e-mail address, role, password (stored only as a one-way hash), time zone, caller ID and notification choices for people who are added to a workspace or to the platform administration.',
                'Workspace content: everything a Customer and its users put into the Service, such as contacts (names, e-mail addresses, phone numbers, tags, notes, tasks, attachments), custom records and fields, forms and their submissions, automations, templates, and imported data.',
                'Messages: the content, recipients, status and delivery reports of e-mails and text messages sent through the Service, and open and click events when a Customer chooses to track them.',
                'Usage and device information: sign-in times, IP address, browser type, pages and features used, and error and performance logs, which we use to run and protect the Service.',
                'Support and requests: messages exchanged with our team, and requests for more users, data objects or automations, including who made them.',
                'Billing: for paid plans, the details needed to invoice a Customer (company name, billing contact and plan). Card numbers are handled by our payment provider and are not stored in the Service.',
            ),
        ] },
        { id: 'how-we-use', title: 'How we use information', blocks: [
            p('We use personal information to:'),
            ul(
                'provide, maintain and secure the Service, including running automations, sending e-mails and text messages that Customers ask us to send, and syncing contacts from connected tools;',
                'create and manage accounts, authenticate people and apply permissions;',
                'respond to enquiries, provide support and tell administrators about requests, failed automations and platform news;',
                'monitor performance, prevent fraud and abuse, and fix problems;',
                'improve and develop the Service, using aggregated or de-identified information where possible;',
                'meet our legal obligations and enforce our terms.',
            ),
            p('We do not sell personal information, and we do not use Customer workspace content for advertising.'),
        ] },
        { id: 'legal-bases', title: 'Legal bases (EEA, UK and similar laws)', blocks: [
            p('Where the law requires a legal basis, we rely on: performing our contract with you or your company; our legitimate interests in running, securing and improving the Service and in answering enquiries; compliance with legal obligations; and your consent where we ask for it (for example, for marketing e-mails). You can withdraw consent at any time.'),
        ] },
        { id: 'sharing', title: 'Who we share information with', blocks: [
            p('We share information only as needed to provide the Service:'),
            ul(
                'Service providers (sub-processors) that host our infrastructure, send e-mail and text messages on our behalf, and provide monitoring and support tools. Today these include an e-mail delivery provider (for example SendGrid, or an SMTP server a Customer or we configure) and a messaging provider (Twilio) for text messages.',
                'Integrations a Customer turns on. If a Customer connects its own account at another service, such as Keap, Twilio or a webhook endpoint, information flows to and from that service as the Customer configures. That service handles it under its own terms.',
                'Platform administrators and support staff. Authorised members of our team can open a Customer\'s workspace in support mode to help with a problem. They are bound by confidentiality and may use this access only for support and security.',
                'Professional advisers, authorities and courts when we are legally required to, or to protect rights, safety and the Service.',
                'A successor, if we are involved in a merger, acquisition or sale of assets. We will tell you if your information becomes subject to a different privacy policy.',
            ),
            p('A current list of our sub-processors is available on request.'),
        ] },
        { id: 'cookies', title: 'Cookies and similar technologies', blocks: [
            p('Our website does not use advertising or cross-site tracking cookies, and it loads its fonts and images from its own address, so your browser does not contact other companies when you open a page.'),
            p('When bot protection is switched on, the sign-in and sign-up pages of the service use Google reCAPTCHA. It loads a script from Google, uses cookies and reads some information about your browser and how you interact with the page to tell people from automated programs, and sends that to Google, which handles it under its own privacy policy and terms.'),
            p('The Service uses a small number of cookies and browser storage that it needs to work: a session cookie to keep you signed in, an optional "remember me" cookie if you choose it, a security token that protects forms, and local settings such as your chosen theme, collapsed menus and card layout. You can clear these in your browser, but parts of the Service will not work without the essential ones.'),
            p('When a Customer sends e-mail through the Service, it can include a small tracking image and tracked links to record opens and clicks. That is the Customer\'s choice, and the Customer is responsible for informing its recipients.'),
        ] },
        { id: 'security', title: 'How we protect information', blocks: [
            p('We use measures designed to protect information, including:'),
            ul(
                'a separate database for every Customer, so one Customer\'s records are not stored with another\'s;',
                'encryption in transit (HTTPS) and encryption of stored secrets such as integration credentials and API tokens;',
                'one-way hashing of passwords, sign-in protections and permission templates that limit what each user can see and do;',
                'activity history on records, and logs of automation runs and message delivery;',
                'access to production systems limited to authorised staff.',
            ),
            p('No system is completely secure. If we learn of a breach that affects personal information, we will notify affected Customers without undue delay and as the law requires.'),
        ] },
        { id: 'retention', title: 'How long we keep information', blocks: [
            p('We keep workspace content for as long as the Customer\'s account is active. A Customer can delete records and users at any time, and can export its data. After a Customer account ends, we delete or anonymise its workspace within a reasonable period (normally within 90 days), except where we must keep something longer for legal, security or accounting reasons. Deleted information may remain in backups for a limited time until they are overwritten.'),
            p('We keep website enquiries for as long as needed to respond and for a reasonable period afterwards, and logs for a limited period needed for security and troubleshooting.'),
        ] },
        { id: 'transfers', title: 'International transfers', blocks: [
            p('We and our service providers may process information in countries other than your own, including countries that may not have the same data protection laws. Where required, we use safeguards such as standard contractual clauses to protect information that is transferred.'),
        ] },
        { id: 'your-rights', title: 'Your rights and choices', blocks: [
            p('Depending on where you live, you may have the right to access, correct, delete or export your personal information, to object to or restrict certain uses, to withdraw consent, and to complain to your data protection authority. California residents have the right to know, delete and correct personal information and to opt out of its sale or sharing. We do not sell or share personal information for cross-context behavioural advertising.'),
            p('To use these rights, contact us using the details below. We may need to verify your identity first. If your information is in a Customer\'s workspace, we will refer your request to that Customer or help them to act on it.'),
            p('You can unsubscribe from our marketing e-mails at any time using the link in them.'),
        ] },
        { id: 'customer-messages', title: 'Messages sent through the Service', blocks: [
            p('Customers use the Service to e-mail and text their own contacts. Each Customer is responsible for having permission to contact people, for honouring unsubscribe and opt-out requests, and for following the anti-spam and marketing laws that apply to it. The Service records e-mail bounces, spam reports and unsubscribes and stops sending to those addresses.'),
        ] },
        { id: 'children', title: 'Children', blocks: [
            p('The Service and our website are for businesses and are not directed to children. We do not knowingly collect personal information from children under 16. If you believe a child has given us information, please contact us and we will delete it.'),
        ] },
        { id: 'changes', title: 'Changes to this policy', blocks: [
            p(`We may update this policy as the Service or the law changes. We will post the new version here with a new "last updated" date and, for important changes, tell account administrators. This version is effective ${legal.effective}.`),
        ] },
        { id: 'contact', title: 'Contact us', contact: true, blocks: [
            p(`For questions about this policy or to use your rights, contact ${E}:`),
        ] },
    ],
};

export const terms: { intro: string; sections: Section[] } = {
    intro: `These Terms of Use ("Terms") are an agreement between you and ${E} ("we", "us") about your use of our website and the ${E} service (the "Service"). If you use the Service for a company, you accept these Terms for that company and confirm you have authority to do so. If you do not agree, do not use the Service.`,
    sections: [
        { id: 'the-service', title: 'The Service', blocks: [
            p('The Service is a hosted CRM. A company that signs up (the "Customer") receives its own workspace where its users manage contacts, custom records, automations, forms and messages by e-mail and SMS, and can connect other tools. Features, plans and limits may change as the Service develops. Some features depend on third-party providers, described below.'),
            p('Your plan sets limits, for example on the number of active users, data objects and automations per data object. A Customer can see how much it uses and can request more; the price is shown before the request is confirmed, and extra capacity is added only after it is approved.'),
        ] },
        { id: 'accounts', title: 'Accounts and security', blocks: [
            p('Administrators add people to a workspace. You must give accurate information and keep your credentials secret. You are responsible for everything done under your account and must tell us promptly of any unauthorised use. A Customer is responsible for its users, for the permissions it grants them, and for removing people who no longer need access.'),
            p('You must be at least 18, or the age of majority where you live, to accept these Terms.'),
        ] },
        { id: 'customer-data', title: 'Customer data', blocks: [
            p('"Customer Data" means the information a Customer and its users put into the Service, including contacts, records, files and messages. The Customer owns its Customer Data. It gives us a limited licence to host, process, transmit and display it only to provide, secure and support the Service and as these Terms and our Privacy Policy allow.'),
            p('The Customer is responsible for the accuracy and legality of Customer Data, for having the notices, permissions and lawful bases needed to collect it and to contact the people in it, and for what its automations, forms and webhooks do. We process personal data in Customer Data as a processor; our Privacy Policy and, where required, a data processing agreement describe how.'),
            p('A Customer can export or delete its data at any time while its account is active. After the account ends, Section 14 applies.'),
        ] },
        { id: 'acceptable-use', title: 'Acceptable use', blocks: [
            p('You agree not to:'),
            ul(
                'break any law or regulation, or infringe anyone\'s rights;',
                'send spam, or contact people without the permission the law requires, or ignore unsubscribe and opt-out requests;',
                'send unlawful, harassing, deceptive, hateful or malicious content, or content that infringes intellectual property or privacy;',
                'upload malware, or try to probe, disrupt, overload or bypass the security or limits of the Service;',
                'access another Customer\'s workspace or data, or use the Service to build a competing product;',
                'resell the Service or share one login among several people, except as your plan allows;',
                'use the Service for anything that would put people or systems at risk.',
            ),
            p('We may remove content and limit or suspend access that we reasonably believe breaks this section.'),
        ] },
        { id: 'messaging', title: 'E-mail and SMS compliance', blocks: [
            p('When you send e-mail or text messages through the Service, you are the sender and are responsible for complying with all laws that apply to you and your recipients, such as CAN-SPAM, GDPR and the ePrivacy rules, CASL, the TCPA and similar telemarketing and mobile messaging rules, and the policies of carriers and our providers.'),
            p('In particular you must obtain and keep the consent the law requires before you message someone, identify yourself, include a working way to opt out and honour it promptly, and send only to people who have not opted out. Messages may be delayed, filtered or blocked by carriers and mailbox providers, and we cannot guarantee delivery.'),
            p('E-mail sending depends on a mail server that the platform or a Customer configures. Automations that send e-mail or text messages will not deliver until that channel is set up. The Service tells you when it is not.'),
        ] },
        { id: 'third-parties', title: 'Third-party services and integrations', blocks: [
            p('The Service can work with third-party services, including Twilio for text messages, e-mail delivery providers such as SendGrid or an SMTP server, Keap for contact sync, and any system you connect through webhooks. A Customer may use our shared account for some of these or connect its own account. Your use of a third-party service is subject to that provider\'s terms and privacy policy, and we are not responsible for it. If you connect your own account, you are responsible for its credentials, charges and compliance.'),
        ] },
        { id: 'fees', title: 'Fees and payment', blocks: [
            p('Paid plans, extra capacity and any usage-based charges are as agreed in your order, quote or plan description. Unless stated otherwise, fees are charged in advance, are non-refundable, and exclude taxes, which you are responsible for. Third-party charges, such as your own Twilio usage, are billed by those providers. We may change prices for future periods with reasonable notice.'),
        ] },
        { id: 'availability', title: 'Availability and support', blocks: [
            p('We work to keep the Service available and to fix problems promptly, but we do not promise it will be uninterrupted or error-free. We may carry out maintenance, and background services such as automations, message sending and scheduled jobs may be paused for a Customer, for example while we investigate a problem. We will give support as described in your plan.'),
        ] },
        { id: 'intellectual-property', title: 'Our intellectual property', blocks: [
            p(`The Service, including its software, design, text, logos and the name ${E}, belongs to us and our licensors. We grant you a limited, non-exclusive, non-transferable right to use the Service for your internal business purposes while these Terms apply. You may not copy, modify, reverse engineer or create derivative works of the Service except as the law allows. If you send us feedback, we may use it without obligation to you.`),
        ] },
        { id: 'confidentiality', title: 'Confidentiality', blocks: [
            p('Each party will keep the other\'s non-public information confidential, use it only for the purposes of these Terms, and protect it with reasonable care, except for information that is public, already known, independently developed, or that it must disclose by law (after telling the other party where it may).'),
        ] },
        { id: 'warranties', title: 'Disclaimers', blocks: [
            p('The Service is provided "as is" and "as available". To the fullest extent the law allows, we disclaim all warranties, express or implied, including merchantability, fitness for a particular purpose, non-infringement, and that the Service will meet your requirements or that messages will be delivered. Nothing on our website or in the Service is legal, tax or compliance advice.'),
        ] },
        { id: 'liability', title: 'Limitation of liability', blocks: [
            p('To the fullest extent the law allows, we will not be liable for indirect, incidental, special, consequential or punitive damages, or for lost profits, revenue, goodwill or data, arising from or related to the Service, even if advised they are possible. Our total liability for all claims about the Service in any 12-month period is limited to the amount you paid us for the Service in the 12 months before the event that gave rise to the claim (or USD 100 if you paid nothing). These limits do not exclude liability that cannot be excluded by law, such as for fraud or for death or personal injury caused by negligence.'),
        ] },
        { id: 'indemnity', title: 'Indemnity', blocks: [
            p('You will defend and indemnify us against claims, losses and costs (including reasonable legal fees) arising from your Customer Data, your messages to your contacts, your breach of these Terms, or your violation of law or third-party rights, except to the extent caused by us.'),
        ] },
        { id: 'termination', title: 'Suspension and termination', blocks: [
            p('You may stop using the Service at any time. A Customer may ask us to close its account. We may suspend or end access if you breach these Terms, if required by law, if your use poses a security risk, or if fees are overdue, and we will give notice where we reasonably can.'),
            p('After an account ends, the Customer can ask for an export of its data for a short period (normally 30 days), after which we delete or anonymise its workspace as described in our Privacy Policy. Sections that by their nature should survive, including those on Customer Data, intellectual property, disclaimers, liability and indemnity, continue after termination.'),
        ] },
        { id: 'changes', title: 'Changes to these Terms', blocks: [
            p(`We may update these Terms. We will post the new version with a new "last updated" date and, for material changes, tell account administrators in advance. If you keep using the Service after the effective date, you accept the changes. This version is effective ${legal.effective}.`),
        ] },
        { id: 'general', title: 'General', blocks: [
            p('These Terms, together with our Privacy Policy and your order or plan, are the whole agreement about the Service. If part of them is unenforceable, the rest still applies. A failure to enforce a right is not a waiver of it. You may not assign these Terms without our consent; we may assign them to a successor. Neither party is liable for delays caused by events beyond its reasonable control. The laws of the jurisdiction in which we are established govern these Terms, without regard to conflict of law rules, and the courts of that jurisdiction have exclusive authority, except where mandatory consumer or data protection law gives you other rights.'),
        ] },
        { id: 'contact', title: 'Contact us', contact: true, blocks: [
            p('Questions about these Terms:'),
        ] },
    ],
};
