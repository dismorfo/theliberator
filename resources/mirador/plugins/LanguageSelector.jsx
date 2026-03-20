import React, { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import ListItemIcon from '@mui/material/ListItemIcon'
import ListItemText from '@mui/material/ListItemText'
import { FormControl } from '@mui/material'
import Typography from '@mui/material/Typography'
import Accordion from '@mui/material/Accordion'
import AccordionDetails from '@mui/material/AccordionDetails'
import AccordionSummary from '@mui/material/AccordionSummary'
import ExpandMoreIcon from '@mui/icons-material/ExpandMore'
import CheckIcon from '@mui/icons-material/CheckSharp'
import MenuItem from '@mui/material/MenuItem'
import { updateConfig } from 'mirador/src/state/actions/config'
import { getLanguagesFromConfigWithCurrent } from 'mirador/src/state/selectors/config'
import { getManifestoInstance } from 'mirador/src/state/selectors/manifests'
import PropTypes from 'prop-types'


const translations = {
  en: {
    availableLanguages: 'Available languages',
    collapseSection: 'Collapse {{section}}',
    expandSection: 'Expand {{section}}',
  },
  ar: {
    availableLanguages: 'اللغات المتوفرة',
    collapseSection: 'طي {{section}}',
    expandSection: 'توسيع {{section}}',
  },
  // no translation for fa
  fa: {
    availableLanguages: 'Available languages',
    collapseSection: 'Collapse {{section}}',
    expandSection: 'Expand {{section}}',
  },  
}

const langstyles = {
  container: {
    width: '100%',
    marginTop: '16px',
    paddingBlockStart: '16px',
    paddingInlineStart: '0',
    paddingInlineEnd: '8px',
    paddingBlockEnd: '8px',
    borderBottom: 'none',
    borderTop: '0.5px solid rgba(0, 0, 0, 0.25)',
  },
  content: {
    display: 'flex',
    justifyContent: 'space-around',
    alignItems: 'start',
    flexDirection: 'column',
    margin: '0',
  },
  formControl: {
    width: '100%',
    marginTop: '0', 
  },
  sectionTitle: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    width: '100%',
    paddingRight: '8px', 
  },
  selectMenu: {
    '&::before': {
      content: "''",
      borderBottom: '.1px solid rgba(0, 0, 0, 0.2)',
    },
  },
  listItemText: {
    paddingInlineStart: '20px',
    paddingInlineEnd: '20px',
    marginBlockStart: '4px',
    marginBlockEnd: '4px',
  },
}

const LanguageSelector = ({
  rootElem = {},
  handleClick = () => {},
  languages = [],
  resourceLanguages = [],
  windowId,
}) => {
  const { t } = useTranslation()
  const [open, setOpen] = useState(true)

  const handleChange = useCallback((_event, isExpanded) => {
    setOpen(isExpanded)
  }, [])
  
  // If there is only one language, don't show the language selector.
  if (resourceLanguages.length < 2) return null

  const windowIdShort = windowId?.replace(/^window-/, '') || 'default'
  const sectionId = `language-selector-${windowIdShort}`
  const sectionLabel = t('availableLanguages')
  return (
    <div style={langstyles.container}>
      <Accordion
        slotProps={{ heading: { component: 'h4' } }}
        id={sectionId}
        elevation={0}
        expanded={open}
        onChange={handleChange}
        disableGutters
        square
        variant="compact"
      >
        <AccordionSummary
          id={`${sectionId}-header`}
          aria-controls={`${sectionId}-content`}
          aria-label={t(open ? 'collapseSection' : 'expandSection', { section: sectionLabel })}
          expandIcon={<ExpandMoreIcon />}
        >
          <Typography variant="overline">
            {sectionLabel}
          </Typography>
        </AccordionSummary>
        <AccordionDetails>
          <FormControl style={langstyles.formControl}>
            {
              languages.map(language => {
                if (resourceLanguages.includes(language.locale)) {
                  return (
                    <MenuItem
                      key={language.locale}
                      onClick={() => {
                        handleClick({ rootElem, language})
                      }}
                    >
                      <ListItemIcon>{language.current && <CheckIcon />}</ListItemIcon>
                      <ListItemText 
                        primaryTypographyProps={{ variant: 'body1' }}
                        style={langstyles.listItemText}
                      >
                        {language.label}
                      </ListItemText>
                    </MenuItem>
                  )
                }
                return null
              })
            }
          </FormControl>
        </AccordionDetails>
      </Accordion>
    </div>
  )
}

const mapDispatchToProps = (dispatch, { afterSelect }) => ({
  handleClick: ({rootElem, language} ) => {
    const { locale } = language

    let dir = 'ltr'

    if (locale === 'ar' || locale === 'fa') {
      dir = 'rtl'
    }

    rootElem.dir = dir

    dispatch(updateConfig({ language: locale }))

    afterSelect && afterSelect()
  },
})

const mapStateToProps = (state, { windowId }) => {
  return {
    languages: getLanguagesFromConfigWithCurrent(state),
    resourceLanguages: getManifestoInstance(state, { windowId }).getLabel().reduce((langs, lang) => {
      langs.push(lang._locale)
      return langs
    }, []),
    rootElem: document.getElementById(state.config.id),
    windowId,
  }
}

LanguageSelector.propTypes = {
  handleClick: PropTypes.func,
  languages: PropTypes.array,
  resourceLanguages: PropTypes.array,
  rootElem: PropTypes.object,
  windowId: PropTypes.string,
}

export default {
  target: 'CanvasInfo',
  mode: 'add',
  component: LanguageSelector,
  mapDispatchToProps,
  mapStateToProps,
  config: {
    translations,
  },
}
