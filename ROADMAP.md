# HIFQA Fraud Detector Roadmap

This document outlines the current status of the project and planned enhancements.

## ✅ Completed

### Core Engine
- Fraud signal extraction
- Rule-based risk scoring
- Configurable risk thresholds
- Automatic order review and blocking

### AI Integration
- Multi-provider AI architecture
- Provider-specific embeddings
- Pinecone vector similarity search
- Seed pattern loading
- Function/tool calling support
- Merchant feedback loop
- Per-merchant learning
- AI review after checkout, for borderline orders only, with an hourly limit (1.1.0)

### Dashboard & Administration
- React-based admin dashboard
- WordPress REST API
- Settings page
- End-to-end testing

## 🚧 Planned

### Platform Support
- Easy Digital Downloads
- SureCart
- Shopify integration

### Next releases
- 1.2.0: card-testing guard before payment (log-only by default), a
  decision log with labels from merchant actions and payment disputes,
  two-way signal weights with noisy-OR scoring (shadow mode first), and
  false positives saved as legitimate patterns
- 1.3.0: replay evaluation on synthetic data (does the AI review, or
  pattern context, improve decisions at all?), fixed velocity windows,
  and a small random sample of low-score orders sent for evaluation only

### Improvements
- Additional automated tests
- Analytics and reporting
- Community pattern sharing (opt-in)

## 💡 Future Ideas

- White-label edition
- SaaS management platform