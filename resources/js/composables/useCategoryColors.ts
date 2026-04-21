/**
 * Category color/gradient mapping
 * Maps category slugs to their respective gradient colors
 */

export const categoryGradients: Record<string, string> = {
    // Styles
    'bossa-nova': 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)', // Orange-Red (default)
    'samba': 'linear-gradient(135deg, #27ae60 0%, #2ecc71 100%)', // Green
    'jazz': 'linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%)', // Purple
    'mpb': 'linear-gradient(135deg, #3498db 0%, #2980b9 100%)', // Blue
    'choro': 'linear-gradient(135deg, #e67e22 0%, #d35400 100%)', // Dark Orange
    'forro': 'linear-gradient(135deg, #1abc9c 0%, #16a085 100%)', // Teal
    'sertanejo': 'linear-gradient(135deg, #795548 0%, #5d4037 100%)', // Brown
    'reggae': 'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)', // Red
    'funk': 'linear-gradient(135deg, #ff6b9d 0%, #c44569 100%)', // Pink

    // Difficulty levels
    'beginner': 'linear-gradient(135deg, #2ecc71 0%, #27ae60 100%)', // Green
    'intermediate': 'linear-gradient(135deg, #f39c12 0%, #e67e22 100%)', // Orange
    'advanced': 'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)', // Red
    'expert': 'linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%)', // Purple

    // Product types
    'full-song': 'linear-gradient(135deg, #3498db 0%, #2980b9 100%)', // Blue
    'study-piece': 'linear-gradient(135deg, #1abc9c 0%, #16a085 100%)', // Teal
    'exercise': 'linear-gradient(135deg, #e67e22 0%, #d35400 100%)', // Orange
    'technique': 'linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%)', // Purple
};

/**
 * Get gradient for a category slug
 * Falls back to default SBN gradient if not found
 */
export function getCategoryGradient(slug: string | undefined): string {
    if (!slug) return 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)';
    return categoryGradients[slug] || 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)';
}

/**
 * Get CSS style object with category gradient
 */
export function getCategoryStyle(slug: string | undefined): Record<string, string> {
    return {
        '--category-gradient': getCategoryGradient(slug),
    };
}

/**
 * Composable for category colors
 */
export function useCategoryColors() {
    return {
        getGradient: getCategoryGradient,
        getStyle: getCategoryStyle,
        gradients: categoryGradients,
    };
}
