# Bunny Media Offload CSS Structure

This directory contains the modular CSS files for the Bunny Media Offload plugin. The CSS has been organized into separate modules for better maintainability and reusability.

## File Structure

### Main File
- **admin.css** - Main stylesheet that imports all modules and contains legacy styles

### Core Modules
- **core.css** - Utility classes and reusable core styles
- **components.css** - UI components (cards, buttons, progress bars, etc.)
- **pages.css** - Page-specific styles (settings, optimization, etc.)
- **documentation.css** - Documentation page styles (tabs, code blocks, guides)

## CSS Architecture

### Core.css
Contains utility classes for:
- Display states (hidden/visible)
- Text alignment
- Margins and spacing
- Dynamic progress bar styles
- Lists and indentation

### Components.css
Contains reusable UI components:
- Progress bars (`bunny-progress-bar`, `bunny-progress-fill`)
- Cards (`bunny-card`, `bunny-stat-card`)
- Status indicators (`bunny-status-*`)
- Buttons (`bunny-button-*`)
- Action containers (`bunny-actions`, `bunny-quick-actions`)
- Notices (`bunny-notice`)
- Loading spinner (`bunny-loading`)

### Pages.css
Contains page-specific styles:
- Settings navigation tabs
- Optimization cards and targets
- WPML integration styles
- Responsive design breakpoints
- Page-specific layouts

### Documentation.css
Contains documentation page styles:
- Tab navigation system
- Info cards (success, warning, info)
- Step guides with numbered indicators
- Code blocks with copy functionality
- Configuration tables
- Feature grids
- Tips and troubleshooting sections
- Responsive design for mobile

## CSS Classes Reference

### Display States
- `bunny-hidden` - Hide element
- `bunny-visible` - Show element
- `bunny-button-hidden/visible` - Button states
- `bunny-controls-hidden/visible` - Control container states
- `bunny-status-hidden/visible` - Status container states
- `bunny-errors-hidden/visible` - Error container states

### Lists
- `bunny-list` - Basic list with margin and padding reset
- `bunny-list-indent` - Indented list (margin-left: 20px)

### Progress Bars
Dynamic width progress bars use inline styles for width values that are calculated in PHP:
```html
<div class="bunny-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
```

## Migration Notes

### Migration Completed ✅
All inline and embedded CSS has been successfully moved to external stylesheets with a modular structure.

### Inline Styles Removed
- All CSS `<style>` blocks have been moved to external stylesheets
- Large documentation page CSS block (300+ lines) → documentation.css
- Media library CSS block (50+ lines) → components.css
- Settings and optimization CSS blocks → pages.css

### Inline Style Attributes
Most inline `style` attributes have been replaced with CSS classes, except for:
- Dynamic width progress bars (functional requirement)
- PHP-calculated values that must remain dynamic

### Final Status
- **4 remaining inline styles** - All functional (dynamic progress bar widths)
- **0 CSS style blocks** - All moved to external files
- **5 modular CSS files** - Organized by purpose and reusability
- **100% backward compatibility** - All existing functionality preserved

### Backward Compatibility
The existing admin.css file imports all modules, ensuring backward compatibility.

## Best Practices

1. **Use existing classes** before creating new ones
2. **Follow BEM-like naming** with `bunny-` prefix
3. **Add new styles to appropriate modules**:
   - Utilities → core.css
   - Components → components.css  
   - Page-specific → pages.css
   - Documentation features → documentation.css
4. **Avoid inline styles** except for dynamic values
5. **Use CSS classes for state management** instead of inline display properties

## Development Workflow

When adding new features:
1. Check if existing classes can be reused
2. Add new utility classes to core.css
3. Add new components to components.css
4. Add page-specific styles to pages.css
5. Add documentation features to documentation.css
6. Update this README if adding new patterns 