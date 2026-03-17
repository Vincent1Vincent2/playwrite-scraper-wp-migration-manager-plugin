# Migration Manager WordPress Plugin - Project Context

## 📋 **Project Overview**

I'm developing a WordPress plugin called "Migration Manager" that scrapes content from external websites using a Python API and allows users to migrate that content into WordPress. The plugin features intelligent content grouping and drag-and-drop functionality.

## 🗂️ **File Structure**

```
migration-manager/
├── migration-manager.php           # Main plugin file
├── assets/
│   ├── css/admin.css              # Admin styles with drag & drop CSS
│   └── js/admin.js                # Main JavaScript with drag & drop functionality
└── includes/
    ├── admin-pages/
    │   ├── main-page.php          # Main scraping interface
    │   └── settings-page.php      # Settings & database management
    └── ajax/
        └── scrape-handler.php     # All AJAX handlers & database functions
```

## 🔧 **Core Functionality Implemented**

### **Main Features:**

- **Content Scraping**: Calls Python API to scrape websites and intelligently groups content
- **Database Storage**: Saves all scrapes with full CRUD operations
- **Load Previous Scrapes**: Users can reload and work with previously scraped data
- **Drag & Drop**: Users can drag any scraped content item and drop it into WordPress text fields
- **Export/Import**: JSON export of scrape data with full metadata
- **Auto Cleanup**: Configurable automatic cleanup of old scrape data

### **Database Schema:**

Table: `wp_migration_manager_scrapes`

- `id` - Auto increment primary key
- `url` - Source URL that was scraped
- `scraped_data` - JSON data of all scraped content
- `scraped_at` - Timestamp
- `status` - 'success', 'failed', etc.

## 🎯 **Key Technical Details**

### **Python API Integration:**

- Connects to external Python scraper API (configurable endpoint)
- Default: `http://localhost:8000`
- API returns JSON with content items: text, links, images, videos
- Supports grouped/wrapper elements with children
- Supports optional **AI post-processing** of section groups (labeling and reordering) via OpenAI or Anthropic

### **Content Types Handled:**

- **Text**: HTML elements with text content, includes element type, classes, IDs
- **Links**: URLs with anchor text
- **Images**: Including CSS background images, with alt text and metadata
- **Videos**: With poster images and format info
- **Grouped Elements**: Parent elements that contain multiple child elements

### **Drag & Drop System:**

- Makes all scraped content draggable with visual indicators
- Drop targets: any textarea, text input, or URL input in WordPress
- Visual feedback: hover effects, drag indicators (⋮⋮), blinking on hold
- Smart content formatting: links include "Text - URL" format

## ⚙️ **Settings & Configuration**

- **API Configuration**: Base URL, timeout settings, connection testing
- **AI Settings**:
  - Enable/disable AI post-processing
  - Choose provider (`None`, `OpenAI`, `Anthropic`)
  - Configure provider API key and optional model name
  - Test AI connection from the settings page with a small prompt ("Are you ready?")
- **Storage Limits**: Max scrapes to store, auto-cleanup rules
- **Database Management**: Manual cleanup, export all data, clear all data
- **Statistics Dashboard**: Shows total, successful, failed scrapes with dates

## 🎨 **UI Features**

- WordPress admin integration with proper styling
- Statistics grid showing content breakdowns
- Recent scrapes table with load functionality
- Export buttons for individual scrapes or all data
- Advanced options for post creation (category, author, status)

## 🔄 **AJAX Actions Implemented**

- `migration_manager_scrape` - Main scraping function
- `migration_manager_load_scrape` - Load previous scrape data
- `migration_manager_test_api` - Test Python API connection/health
- `migration_manager_test_ai` - Test AI provider connectivity (uses `/ai-test` on the scraper)
- `migration_manager_cleanup_data` - Manual data cleanup
- `migration_manager_clear_all_data` - Clear all scrape data
- `migration_manager_export_all_data` - Export all data as JSON

## 📝 **Current Status**

✅ **Completed:**

- Full scraping and storage system
- Load previous scrapes functionality
- Drag & drop content manipulation
- Settings page with full database management
- Export/import functionality
- Auto cleanup with cron scheduling

🔄 **Placeholder Functions (Ready for Implementation):**

- `createPosts()` - Convert scraped content to WordPress posts
- `createPages()` - Convert scraped content to WordPress pages
- `saveDraft()` - Save as draft posts
- `togglePreviewMode()` - Preview mode for content

## 💡 **Key Design Decisions**

- All scraped data stored as JSON in database for flexibility
- Drag & drop works with any WordPress text field, not just plugin areas
- Auto-cleanup and storage limits to prevent database bloat
- Comprehensive error handling and user feedback
- WordPress coding standards and security best practices

## 🚀 **Usage Flow**

1. User enters website URL in main interface
2. Plugin calls Python API to scrape content
3. Results displayed with intelligent grouping and statistics
4. User can drag any content item to WordPress text fields
5. Previous scrapes are saved and can be reloaded anytime
6. Export/cleanup tools available in settings

---

**This context should help you understand the complete Migration Manager plugin architecture and current implementation status.**
